<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../src/config/Database.php';
require_once __DIR__ . '/../src/models/Banner.php';

use FAS\Config\Database;
use FAS\Models\Banner;

$auth = new AdminAuth();
$auth->requireLogin();

$db = Database::getInstance()->getConnection();
$bannerModel = new Banner($db);

$success = '';
$error = '';

// Ensure the banners table exists (auto-migrate)
try {
    $db->query("SELECT 1 FROM banners LIMIT 1");
} catch (Exception $e) {
    try {
        $driver = $db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $db->exec("CREATE TABLE IF NOT EXISTS banners (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                message TEXT NOT NULL,
                bg_color TEXT NOT NULL DEFAULT 'danger',
                text_color TEXT NOT NULL DEFAULT 'white',
                link_url TEXT,
                link_text TEXT,
                is_dismissible INTEGER NOT NULL DEFAULT 1,
                is_active INTEGER NOT NULL DEFAULT 1,
                sort_order INTEGER NOT NULL DEFAULT 0,
                show_countdown INTEGER NOT NULL DEFAULT 0,
                countdown_end TEXT,
                starts_at TEXT,
                ends_at TEXT,
                created_at TEXT DEFAULT (datetime('now')),
                updated_at TEXT DEFAULT (datetime('now'))
            )");
        } else {
            $db->exec("CREATE TABLE IF NOT EXISTS banners (
                id INT AUTO_INCREMENT PRIMARY KEY,
                message TEXT NOT NULL,
                bg_color VARCHAR(30) NOT NULL DEFAULT 'danger',
                text_color VARCHAR(30) NOT NULL DEFAULT 'white',
                link_url VARCHAR(500),
                link_text VARCHAR(255),
                is_dismissible BOOLEAN NOT NULL DEFAULT TRUE,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                sort_order INT NOT NULL DEFAULT 0,
                show_countdown BOOLEAN NOT NULL DEFAULT FALSE,
                countdown_end DATETIME,
                starts_at DATETIME,
                ends_at DATETIME,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_is_active (is_active),
                INDEX idx_sort_order (sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        }
    } catch (Exception $createEx) {
        $error = 'Database setup error: ' . $createEx->getMessage();
    }
}

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'create':
            try {
                $bannerModel->create($_POST);
                $success = 'Banner created successfully!';
            } catch (Exception $e) {
                $error = 'Error creating banner: ' . $e->getMessage();
            }
            break;

        case 'update':
            try {
                $bannerModel->update((int) $_POST['id'], $_POST);
                $success = 'Banner updated successfully!';
            } catch (Exception $e) {
                $error = 'Error updating banner: ' . $e->getMessage();
            }
            break;

        case 'delete':
            try {
                $bannerModel->delete((int) $_POST['id']);
                $success = 'Banner deleted successfully!';
            } catch (Exception $e) {
                $error = 'Error deleting banner: ' . $e->getMessage();
            }
            break;
    }
}

$banners = [];
if (empty($error)) {
    try {
        $banners = $bannerModel->getAll();
    } catch (Exception $e) {
        $error = 'Error loading banners: ' . $e->getMessage();
    }
}

$colorOptions = [
    'danger'  => 'Red (Danger)',
    'warning' => 'Yellow (Warning)',
    'success' => 'Green (Success)',
    'info'    => 'Cyan (Info)',
    'primary' => 'Blue (Primary)',
    'dark'    => 'Dark',
    'secondary' => 'Grey (Secondary)',
];

$textColorOptions = [
    'white' => 'White',
    'dark'  => 'Dark',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Banner Management - Admin</title>
    <link rel="shortcut icon" href="../gallery/favicons/favicon.png">
    <link rel="manifest" href="/admin/manifest.json">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link href="https://unpkg.com/aos@2.3.1/dist/aos.css" rel="stylesheet">
    <link rel="stylesheet" href="css/admin-style.css">
</head>
<body class="bg-light">
    <?php include __DIR__ . '/includes/nav.php'; ?>
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h1>
                <i class="fas fa-bullhorn me-2"></i>Banner Management
            </h1>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBannerModal">
                <i class="fas fa-plus-circle me-1"></i>Create New Banner
            </button>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?php echo htmlspecialchars($success); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card">
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th>Preview</th>
                                <th>Message</th>
                                <th>Countdown</th>
                                <th>Link</th>
                                <th>Dismissible</th>
                                <th>Schedule</th>
                                <th>Order</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($banners as $banner): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?php echo htmlspecialchars($banner['bg_color']); ?> text-<?php echo htmlspecialchars($banner['text_color']); ?>">
                                            <?php echo htmlspecialchars(mb_strimwidth($banner['message'], 0, 40, '…')); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($banner['message']); ?></td>
                                    <td>
                                        <?php if ($banner['show_countdown'] && $banner['countdown_end']): ?>
                                            <span class="badge bg-info text-dark">
                                                <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime($banner['countdown_end']))); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($banner['link_url']): ?>
                                            <a href="<?php echo htmlspecialchars($banner['link_url']); ?>" target="_blank" rel="noopener noreferrer">
                                                <?php echo htmlspecialchars($banner['link_text'] ?: $banner['link_url']); ?>
                                            </a>
                                        <?php else: ?>
                                            <span class="text-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php echo $banner['is_dismissible'] ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-times text-secondary"></i>'; ?>
                                    </td>
                                    <td>
                                        <?php if ($banner['starts_at'] || $banner['ends_at']): ?>
                                            <small>
                                                <?php echo $banner['starts_at'] ? htmlspecialchars(date('Y-m-d', strtotime($banner['starts_at']))) : '∞'; ?>
                                                →
                                                <?php echo $banner['ends_at'] ? htmlspecialchars(date('Y-m-d', strtotime($banner['ends_at']))) : '∞'; ?>
                                            </small>
                                        <?php else: ?>
                                            <span class="text-muted">Always</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo (int) $banner['sort_order']; ?></td>
                                    <td>
                                        <?php if ($banner['is_active']): ?>
                                            <span class="badge bg-success">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <button class="btn btn-sm btn-outline-secondary me-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editBannerModal"
                                                data-id="<?php echo (int) $banner['id']; ?>"
                                                data-message="<?php echo htmlspecialchars($banner['message'], ENT_QUOTES); ?>"
                                                data-bg_color="<?php echo htmlspecialchars($banner['bg_color'], ENT_QUOTES); ?>"
                                                data-text_color="<?php echo htmlspecialchars($banner['text_color'], ENT_QUOTES); ?>"
                                                data-link_url="<?php echo htmlspecialchars($banner['link_url'] ?? '', ENT_QUOTES); ?>"
                                                data-link_text="<?php echo htmlspecialchars($banner['link_text'] ?? '', ENT_QUOTES); ?>"
                                                data-is_dismissible="<?php echo (int) $banner['is_dismissible']; ?>"
                                                data-is_active="<?php echo (int) $banner['is_active']; ?>"
                                                data-sort_order="<?php echo (int) $banner['sort_order']; ?>"
                                                data-show_countdown="<?php echo (int) $banner['show_countdown']; ?>"
                                                data-countdown_end="<?php echo htmlspecialchars($banner['countdown_end'] ?? '', ENT_QUOTES); ?>"
                                                data-starts_at="<?php echo htmlspecialchars($banner['starts_at'] ?? '', ENT_QUOTES); ?>"
                                                data-ends_at="<?php echo htmlspecialchars($banner['ends_at'] ?? '', ENT_QUOTES); ?>">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Delete this banner?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?php echo (int) $banner['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($banners)): ?>
                                <tr>
                                    <td colspan="9" class="text-center text-muted py-4">No banners created yet</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Create Banner Modal -->
    <div class="modal fade" id="createBannerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-bullhorn me-2"></i>Create New Banner</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php echo renderBannerFormFields($colorOptions, $textColorOptions); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Create Banner</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Banner Modal -->
    <div class="modal fade" id="editBannerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="update">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="fas fa-edit me-2"></i>Edit Banner</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <?php echo renderBannerFormFields($colorOptions, $textColorOptions, 'edit_'); ?>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
    // Show/hide the countdown_end input when the toggle checkbox changes
    function toggleCountdownField(prefix) {
        var cb   = document.getElementById(prefix + 'show_countdown');
        var wrap = document.getElementById(prefix + 'countdown_end_wrap');
        if (cb && wrap) {
            wrap.style.display = cb.checked ? 'block' : 'none';
        }
    }

    // Populate edit modal fields from data attributes
    document.getElementById('editBannerModal').addEventListener('show.bs.modal', function (event) {
        const btn = event.relatedTarget;
        const modal = this;
        const fields = ['id', 'message', 'bg_color', 'text_color', 'link_url', 'link_text',
                        'is_dismissible', 'is_active', 'sort_order',
                        'show_countdown', 'countdown_end',
                        'starts_at', 'ends_at'];

        fields.forEach(function(field) {
            const el = modal.querySelector(field === 'id' ? '#edit_id' : '[name="' + field + '"]');
            if (!el) return;
            const val = btn.getAttribute('data-' + field) || '';
            if (el.type === 'checkbox') {
                el.checked = val === '1';
            } else {
                el.value = val;
            }
        });

        // Sync countdown wrapper visibility
        toggleCountdownField('edit_');
    });

    // Validate countdown_end is set when show_countdown is checked
    function validateCountdownField(prefix) {
        var cb  = document.getElementById(prefix + 'show_countdown');
        var end = document.getElementById(prefix + 'countdown_end');
        if (cb && cb.checked && end && !end.value) {
            end.focus();
            end.setCustomValidity('Please set a countdown end date/time.');
            end.reportValidity();
            return false;
        }
        if (end) end.setCustomValidity('');
        return true;
    }

    document.getElementById('createBannerModal').querySelector('form')
        .addEventListener('submit', function(e) {
            if (!validateCountdownField('')) e.preventDefault();
        });

    document.getElementById('editBannerModal').querySelector('form')
        .addEventListener('submit', function(e) {
            if (!validateCountdownField('edit_')) e.preventDefault();
        });
    </script>

    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
<?php
/**
 * Render shared banner form fields.
 * @param array  $colorOptions     bg_color select options
 * @param array  $textColorOptions text_color select options
 * @param string $prefix           ID/name prefix for the edit modal ('edit_' or '')
 */
function renderBannerFormFields(array $colorOptions, array $textColorOptions, string $prefix = ''): string
{
    ob_start();
    ?>
    <div class="mb-3">
        <label class="form-label">Message <span class="text-danger">*</span></label>
        <textarea name="message" class="form-control" rows="2" required
                  id="<?php echo $prefix; ?>message" placeholder="e.g., Free shipping on orders over $50!"><?php
            /* value populated via JS for edit modal */
        ?></textarea>
    </div>
    <div class="row mb-3">
        <div class="col-6">
            <label class="form-label">Background Color</label>
            <select name="bg_color" class="form-select" id="<?php echo $prefix; ?>bg_color">
                <?php foreach ($colorOptions as $val => $label): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6">
            <label class="form-label">Text Color</label>
            <select name="text_color" class="form-select" id="<?php echo $prefix; ?>text_color">
                <?php foreach ($textColorOptions as $val => $label): ?>
                    <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label">Link URL <small class="text-muted">(optional)</small></label>
        <input type="url" name="link_url" class="form-control"
               id="<?php echo $prefix; ?>link_url" placeholder="https://example.com">
    </div>
    <div class="mb-3">
        <label class="form-label">Link Text <small class="text-muted">(optional)</small></label>
        <input type="text" name="link_text" class="form-control"
               id="<?php echo $prefix; ?>link_text" placeholder="e.g., Shop now">
    </div>
    <div class="row mb-3">
        <div class="col-6">
            <label class="form-label">Starts At <small class="text-muted">(optional)</small></label>
            <input type="datetime-local" name="starts_at" class="form-control"
                   id="<?php echo $prefix; ?>starts_at">
        </div>
        <div class="col-6">
            <label class="form-label">Ends At <small class="text-muted">(optional)</small></label>
            <input type="datetime-local" name="ends_at" class="form-control"
                   id="<?php echo $prefix; ?>ends_at">
        </div>
    </div>
    <div class="mb-3">
        <label class="form-label">Sort Order</label>
        <input type="number" name="sort_order" class="form-control" value="0" min="0"
               id="<?php echo $prefix; ?>sort_order">
        <small class="text-muted">Lower number = shown first</small>
    </div>
    <div class="mb-3 form-check">
        <input type="checkbox" name="is_dismissible" value="1" class="form-check-input"
               id="<?php echo $prefix; ?>is_dismissible" checked>
        <label class="form-check-label" for="<?php echo $prefix; ?>is_dismissible">
            Dismissible (users can close the banner)
        </label>
    </div>
    <div class="mb-3 form-check">
        <input type="checkbox" name="is_active" value="1" class="form-check-input"
               id="<?php echo $prefix; ?>is_active" checked>
        <label class="form-check-label" for="<?php echo $prefix; ?>is_active">Active</label>
    </div>
    <hr>
    <div class="mb-2 form-check">
        <input type="checkbox" name="show_countdown" value="1" class="form-check-input banner-countdown-toggle"
               id="<?php echo $prefix; ?>show_countdown"
               onchange="toggleCountdownField('<?php echo $prefix; ?>')">
        <label class="form-check-label fw-semibold" for="<?php echo $prefix; ?>show_countdown">
            <i class="fas fa-clock me-1"></i>Show Countdown Timer
        </label>
    </div>
    <div class="mb-3" id="<?php echo $prefix; ?>countdown_end_wrap" style="display:none;">
        <label class="form-label">Countdown Ends At <span class="text-danger">*</span></label>
        <input type="datetime-local" name="countdown_end" class="form-control"
               id="<?php echo $prefix; ?>countdown_end">
        <small class="text-muted">A live "X days X hrs X mins X secs" timer will appear in the banner.</small>
    </div>
    <?php
    return ob_get_clean();
}
