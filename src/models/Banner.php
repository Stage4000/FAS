<?php
/**
 * Banner Model
 * Handles alert banner operations for the front-end notification system.
 */

namespace FAS\Models;

class Banner
{
    private const SUPPORTED_DATETIME_FORMATS = ['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Get all active banners that are currently within their scheduled date range,
     * ordered by sort_order ascending.
     */
    public function getActive()
    {
        $sql = "SELECT * FROM banners
                WHERE is_active = 1
                ORDER BY sort_order ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        $now = time();
        $banners = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_filter($banners, function ($banner) use ($now) {
            return $this->isBannerScheduledForDisplay($banner, $now);
        }));
    }

    /**
     * Get all banners for admin management, newest first.
     */
    public function getAll()
    {
        $sql = "SELECT * FROM banners ORDER BY sort_order ASC, id ASC";
        $stmt = $this->db->query($sql);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Get a single banner by ID.
     */
    public function getById($id)
    {
        $sql = "SELECT * FROM banners WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([(int) $id]);
        return $stmt->fetch(\PDO::FETCH_ASSOC);
    }

    /**
     * Create a new banner.
     */
    public function create($data)
    {
        $sql = "INSERT INTO banners
                    (message, bg_color, text_color, link_url, link_text,
                     is_dismissible, is_active, sort_order,
                     show_countdown, countdown_end,
                     starts_at, ends_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['message'],
            $data['bg_color']      ?? 'danger',
            $data['text_color']    ?? 'white',
            !empty($data['link_url'])   ? $data['link_url']   : null,
            !empty($data['link_text'])  ? $data['link_text']  : null,
            $this->checkboxValue($data, 'is_dismissible', 1),
            $this->checkboxValue($data, 'is_active', 1),
            isset($data['sort_order'])     ? (int) $data['sort_order']     : 0,
            isset($data['show_countdown']) ? (int) $data['show_countdown'] : 0,
            !empty($data['countdown_end']) ? $data['countdown_end'] : null,
            !empty($data['starts_at']) ? $data['starts_at'] : null,
            !empty($data['ends_at'])   ? $data['ends_at']   : null,
        ]);
    }

    /**
     * Update an existing banner.
     */
    public function update($id, $data)
    {
        $sql = "UPDATE banners SET
                    message        = ?,
                    bg_color       = ?,
                    text_color     = ?,
                    link_url       = ?,
                    link_text      = ?,
                    is_dismissible = ?,
                    is_active      = ?,
                    sort_order     = ?,
                    show_countdown = ?,
                    countdown_end  = ?,
                    starts_at      = ?,
                    ends_at        = ?
                WHERE id = ?";

        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            $data['message'],
            $data['bg_color']      ?? 'danger',
            $data['text_color']    ?? 'white',
            !empty($data['link_url'])   ? $data['link_url']   : null,
            !empty($data['link_text'])  ? $data['link_text']  : null,
            $this->checkboxValue($data, 'is_dismissible', 0),
            $this->checkboxValue($data, 'is_active', 0),
            isset($data['sort_order'])     ? (int) $data['sort_order']     : 0,
            isset($data['show_countdown']) ? (int) $data['show_countdown'] : 0,
            !empty($data['countdown_end']) ? $data['countdown_end'] : null,
            !empty($data['starts_at']) ? $data['starts_at'] : null,
            !empty($data['ends_at'])   ? $data['ends_at']   : null,
            (int) $id,
        ]);
    }

    /**
     * Delete a banner.
     */
    public function delete($id)
    {
        $sql = "DELETE FROM banners WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([(int) $id]);
    }

    /**
     * Determine whether a banner should currently be shown.
     *
     * @param array $banner Banner row data including optional starts_at and ends_at fields.
     * @param int   $now    Current Unix timestamp in the site's configured timezone.
     *
     * @return bool
     */
    private function isBannerScheduledForDisplay(array $banner, $now)
    {
        $startsAt = $this->parseDateTime($banner['starts_at'] ?? null);
        if ($startsAt === false || ($startsAt !== null && $startsAt > $now)) {
            return false;
        }

        $endsAt = $this->parseDateTime($banner['ends_at'] ?? null);
        if ($endsAt === false || ($endsAt !== null && $endsAt < $now)) {
            return false;
        }

        return true;
    }

    /**
     * Parse supported banner datetime values into a Unix timestamp.
     *
     * Accepts empty values, datetime-local strings, SQL-style datetime strings,
     * and falls back to strtotime() for legacy values.
     *
     * @param mixed $value
     *
     * @return int|false|null
     */
    private function checkboxValue(array $data, string $field, int $default): int
    {
        if (!array_key_exists($field, $data)) {
            return $default;
        }

        return !empty($data[$field]) ? 1 : 0;
    }

    private function parseDateTime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        foreach (self::SUPPORTED_DATETIME_FORMATS as $format) {
            $dateTime = \DateTimeImmutable::createFromFormat($format, $value);
            $errors = \DateTimeImmutable::getLastErrors();

            if ($this->hasValidDateTimeParse($dateTime, $errors)) {
                return $dateTime->getTimestamp();
            }
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? false : $timestamp;
    }

    /**
     * Check whether a DateTimeImmutable parse succeeded without warnings/errors.
     *
     * @param \DateTimeImmutable|false $dateTime
     * @param array|false              $errors
     *
     * @return bool
     */
    private function hasValidDateTimeParse($dateTime, $errors)
    {
        if ($dateTime === false) {
            return false;
        }

        if ($errors === false) {
            return true;
        }

        return $errors['warning_count'] === 0 && $errors['error_count'] === 0;
    }
}
