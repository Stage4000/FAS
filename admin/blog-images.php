<?php
/** Read-only gallery choices for the private blog editor. No upload or storage setup. */
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Content-Type: application/json; charset=utf-8');

function blogImagesReply(int $status, array $data): void {
    // session_start() in the existing auth stack may replace cache headers.
    header('Cache-Control: private, no-store');
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    require_once __DIR__.'/auth.php';
    $auth = new AdminAuth();
    if (!$auth->isLoggedIn()) blogImagesReply(403, ['error'=>'Sign in with an active administrator account.']);
    $auth->requireActiveAdmin();
    // Release the session lock while reading the gallery.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
        header('Allow: GET');
        blogImagesReply(405, ['error'=>'Use GET to browse existing gallery images.']);
    }
    require_once __DIR__.'/../src/blog/BlogContent.php';
    $query = $_GET['q'] ?? '';
    $offset = $_GET['offset'] ?? '0';
    $limit = $_GET['limit'] ?? '40';
    if (!is_string($query) || strlen($query) > 100 || preg_match('//u', $query) !== 1
        || preg_match('/[\x00-\x1F\x7F]/', $query)
        || !is_string($offset) || !preg_match('/\A[0-9]{1,5}\z/', $offset) || (int)$offset > 10000
        || !is_string($limit) || !preg_match('/\A[0-9]{1,2}\z/', $limit) || (int)$limit < 1 || (int)$limit > 80) {
        blogImagesReply(400, ['error'=>'Use a search up to 100 bytes, an offset from 0 to 10000, and a limit from 1 to 80.']);
    }
    $query = trim($query);
    $offset = (int)$offset;
    $limit = (int)$limit;
    $site = realpath(__DIR__.'/..');
    $galleryPath = __DIR__.'/../gallery';
    $root = realpath($galleryPath);
    // Reject a redirected gallery root as well as links encountered beneath it.
    if ($site === false || $root === false || is_link($galleryPath)
        || $root !== $site.DIRECTORY_SEPARATOR.'gallery' || !is_dir($root) || !is_readable($root)) {
        blogImagesReply(503, ['error'=>'The gallery is unavailable.']);
    }
    $prefix = $root.DIRECTORY_SEPARATOR;
    $images = [];
    $pending = [[$root, '', 0]];
    $seen = 0;
    $truncated = false;
    while ($pending) {
        [$directory, $relative, $depth] = array_pop($pending);
        foreach (new DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) continue;
            if (++$seen > 10000) { $truncated = true; break 2; }
            $name = $entry->getFilename();
            if ($name[0] === '.' || $entry->isLink()) continue;
            $path = $entry->getPathname();
            $real = realpath($path);
            // Exact lexical/canonical equality also rejects junctions and parent link aliases.
            if ($real === false || $real !== $path || strncmp($real, $prefix, strlen($prefix)) !== 0) continue;
            $child = $relative.$name;
            if ($entry->isDir()) {
                if ($depth < 8 && $entry->isReadable()) $pending[] = [$real, $child.'/', $depth + 1];
                elseif ($depth >= 8) $truncated = true;
                continue;
            }
            $url = '/gallery/'.$child;
            if (!$entry->isFile() || !$entry->isReadable() || !\FAS\Blog\BlogContent::validGalleryImage($url)
                || ($query !== '' && stripos($child, $query) === false)) continue;
            $size = @getimagesize($real);
            $expected = ['jpg'=>IMAGETYPE_JPEG, 'jpeg'=>IMAGETYPE_JPEG, 'png'=>IMAGETYPE_PNG, 'webp'=>IMAGETYPE_WEBP];
            $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[2] !== ($expected[$extension] ?? null)) continue;
            $images[] = ['url'=>$url, 'name'=>$child, 'width'=>$size[0], 'height'=>$size[1]];
        }
    }
    usort($images, function (array $a, array $b): int { return strcmp($a['url'], $b['url']); });
    blogImagesReply(200, ['images'=>array_slice($images, $offset, $limit), 'offset'=>$offset,
        'limit'=>$limit, 'hasMore'=>count($images) > $offset + $limit, 'truncated'=>$truncated]);
} catch (Throwable $e) {
    error_log('Blog gallery listing unavailable.');
    blogImagesReply(503, ['error'=>'The gallery is unavailable. Try again later.']);
}
