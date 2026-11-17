<?php
declare(strict_types=1);

namespace FAS\Showcase;

/** Dormant escaped fragments. Controllers own authorization, headers and routing. */
final class ShowcaseViews
{
    public static function queue(array $rows, string $detailPath, ?string $queuePath = null, string $status = 'pending_review'): string
    {
        self::path($detailPath);
        if ($queuePath !== null) { self::path($queuePath); }
        if (!in_array($status,['pending_review','approved','rejected','withdrawn'],true)) { throw new \InvalidArgumentException('A known queue state is required.'); }
        if (count($rows) > 50) { throw new \InvalidArgumentException('A bounded review queue is required.'); }
        $html = '<section aria-labelledby="showcase-queue"><h1 id="showcase-queue">Review customer photos</h1>';
        if ($queuePath !== null) {
            $html .= '<nav aria-label="Photo review status">';
            foreach (['pending_review'=>'Pending','approved'=>'Approved','rejected'=>'Rejected','withdrawn'=>'Withdrawn'] as $value=>$label) {
                $html .= '<a href="' . self::escape($queuePath . '?status=' . $value) . '"' . ($value===$status ? ' aria-current="page"' : '') . '>' . $label . '</a> ';
            }
            $html .= '</nav>';
        }
        if (!$rows) { return $html . '<p>No photos match this review queue.</p></section>'; }
        $html .= '<ul>';
        foreach ($rows as $row) {
            self::identity($row);
            $html .= '<li><a href="' . self::url($detailPath, $row['id']) . '">' . self::escape(self::text($row, 'display_name')) . '</a>'
                . ' <span>' . self::escape($row['status']) . '</span><p>' . nl2br(self::escape(self::text($row, 'caption')), false) . '</p></li>';
        }
        $html .= '</ul>';
        if ($queuePath !== null) {
            $last=$rows[count($rows)-1];
            $query=http_build_query(['status'=>$status,'before_time'=>self::text($last,'accepted_at'),'before_id'=>$last['id']],'','&',PHP_QUERY_RFC3986);
            $html .= '<a href="' . self::escape($queuePath . '?' . $query) . '">Older submissions</a>';
        }
        return $html . '</section>';
    }

    /** Accept only readForModeration data after current server-side admin authorization. */
    public static function review(array $row, array $audit, string $csrf, string $action, string $privatePhotoPath): string
    {
        self::identity($row); self::path($action); self::path($privatePhotoPath);
        if (!preg_match('/\A[A-Za-z0-9_-]{32,128}\z/D', $csrf) || count($audit) > 3) {
            throw new \InvalidArgumentException('A server CSRF token and bounded audit are required.');
        }
        foreach (['photo_sha256','consent_text_sha256'] as $key) {
            if (!preg_match('/\A[a-f0-9]{64}\z/D', self::text($row, $key))) { throw new \InvalidArgumentException('An exact review identity is required.'); }
        }
        $html = '<section aria-labelledby="showcase-review"><h1 id="showcase-review">Review customer photo</h1>'
            . '<p>Status: <strong>' . self::escape($row['status']) . '</strong></p>'
            . '<figure><img src="' . self::url($privatePhotoPath, $row['id']) . '" alt="Submitted customer project photo">'
            . '<figcaption>' . self::escape(self::text($row,'display_name')) . '</figcaption></figure>'
            . '<h2>Caption</h2><p>' . nl2br(self::escape(self::text($row,'caption')),false) . '</p>'
            . '<h2>Private contact email</h2><p>' . self::escape(self::text($row,'contact_email')) . '</p>'
            . '<h2>Accepted photo permission</h2><p>Version: ' . self::escape(self::text($row,'consent_version')) . '</p>'
            . '<p>' . nl2br(self::escape(self::text($row,'consent_text')),false) . '</p>'
            . '<p>Accepted at: ' . self::escape(self::text($row,'accepted_at')) . '</p>';
        $choices = ['pending_review'=>['approved'=>'Approve','rejected'=>'Reject','withdrawn'=>'Withdraw'],
            'approved'=>['withdrawn'=>'Withdraw'], 'rejected'=>['withdrawn'=>'Withdraw'], 'withdrawn'=>[]];
        if ($choices[$row['status']]) {
            $html .= '<form method="post" action="' . self::escape($action) . '">';
            foreach (['csrf_token'=>$csrf,'id'=>$row['id'],'version'=>(string)$row['version'],
                'photo_sha256'=>$row['photo_sha256'],'consent_text_sha256'=>$row['consent_text_sha256']] as $name=>$value) {
                $html .= '<input type="hidden" name="' . $name . '" value="' . self::escape($value) . '">';
            }
            $html .= '<p><label for="showcase-reason">Reason for this decision (required)</label>'
                . '<input id="showcase-reason" name="reason" maxlength="500" required></p>'
                . '<p>Approval records your review. Public display still requires the separate enabled publication policy.</p>';
            foreach ($choices[$row['status']] as $value=>$label) { $html .= '<button type="submit" name="decision" value="' . $value . '">' . $label . '</button>'; }
            $html .= '</form>';
        } else { $html .= '<p>This submission is withdrawn. It cannot be approved again.</p>'; }
        $html .= '<h2>Decision history</h2>';
        if (!$audit) { $html .= '<p>No decisions recorded.</p>'; }
        else {
            $html .= '<ol>';
            foreach ($audit as $entry) {
                $html .= '<li>' . self::escape(self::text($entry,'occurred_at')) . ': '
                    . self::escape(self::text($entry,'from_status')) . ' to ' . self::escape(self::text($entry,'to_status'))
                    . '. ' . self::escape(self::text($entry,'reason')) . '</li>';
            }
            $html .= '</ol>';
        }
        return $html . '</section>';
    }

    /** Supply only approvedGallery() output, not private moderation records. */
    public static function gallery(array $items, string $photoPath): string
    {
        self::path($photoPath);
        if (count($items) > 50) { throw new \InvalidArgumentException('A bounded public gallery is required.'); }
        $html = '<section aria-labelledby="showcase-gallery"><h1 id="showcase-gallery">Customer rides and projects</h1>';
        if (!$items) { return $html . '<p>There are no customer photos to display right now.</p></section>'; }
        foreach ($items as $item) {
            // Reject private-row mixups rather than silently accepting an oversized contract.
            $keys=array_keys($item); sort($keys);
            if ($keys !== ['caption','display_name','id'] || !preg_match('/\A[a-f0-9]{32}\z/D', self::text($item,'id'))) {
                throw new \InvalidArgumentException('Only a public showcase projection may be rendered here.');
            }
            $html .= '<figure><img src="' . self::url($photoPath,$item['id']) . '" loading="lazy" decoding="async" alt="Customer project photo">'
                . '<figcaption><strong>' . self::escape(self::text($item,'display_name')) . '</strong><p>'
                . nl2br(self::escape(self::text($item,'caption')),false) . '</p></figcaption></figure>';
        }
        return $html . '</section>';
    }

    private static function identity(array $row): void
    {
        if (!preg_match('/\A[a-f0-9]{32}\z/D', self::text($row,'id')) || !is_int($row['version'] ?? null) || $row['version'] < 1
            || !in_array($row['status'] ?? null,['pending_review','approved','rejected','withdrawn'],true)) {
            throw new \InvalidArgumentException('A stored review snapshot is required.');
        }
    }

    private static function path(string $path): void
    {
        if (!preg_match('~\A/[A-Za-z0-9][A-Za-z0-9_./-]{0,199}\z~D',$path) || strpos($path,'..') !== false) {
            throw new \InvalidArgumentException('A trusted same-origin route is required.');
        }
    }

    private static function url(string $path, string $id): string { return self::escape($path . '?id=' . rawurlencode($id)); }
    private static function text(array $row,string $key): string
    {
        if (!is_string($row[$key] ?? null)) { throw new \InvalidArgumentException('A complete string-valued display record is required.'); }
        return $row[$key];
    }
    private static function escape(string $value): string { return htmlspecialchars($value,ENT_QUOTES | ENT_SUBSTITUTE,'UTF-8'); }
}
