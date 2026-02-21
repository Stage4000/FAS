<?php
/**
 * Banner Model
 * Handles alert banner operations for the front-end notification system.
 */

namespace FAS\Models;

class Banner
{
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
        $now = date('Y-m-d H:i:s');

        $sql = "SELECT * FROM banners
                WHERE is_active = 1
                  AND (starts_at IS NULL OR starts_at <= ?)
                  AND (ends_at   IS NULL OR ends_at   >= ?)
                ORDER BY sort_order ASC, id ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute([$now, $now]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
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
            isset($data['is_dismissible']) ? (int) $data['is_dismissible'] : 1,
            isset($data['is_active'])      ? (int) $data['is_active']      : 1,
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
            isset($data['is_dismissible']) ? (int) $data['is_dismissible'] : 1,
            isset($data['is_active'])      ? (int) $data['is_active']      : 1,
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
}
