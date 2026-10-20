<?php
// post_action.php - Handles actions: create_post, toggle_like, add_comment, toggle_follow
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/auth.php';

if (!is_logged_in()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
    exit;
}

$current_user_id = (int)$_SESSION['user_id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Helper to ensure media directory exists
$upload_dir = __DIR__ . '/uploads/media/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// ACTION 1: CREATE POST
if ($action === 'create_post') {
    $text_content = trim($_POST['text_content'] ?? '');
    $media_path = null;
    $media_type = 'none';

    // File validation & upload handling
    if (isset($_FILES['media_file']) && $_FILES['media_file']['error'] !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['media_file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'error' => 'File upload error code: ' . $file['error']]);
            exit;
        }

        // 50MB Limit: 50 * 1024 * 1024 = 52428800 bytes
        if ($file['size'] > 52428800) {
            echo json_encode(['success' => false, 'error' => 'File exceeds 50MB maximum size limit.']);
            exit;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime_type = $finfo->file($file['tmp_name']);

        $allowed_images = [
            'image/jpeg' => 'jpg',
            'image/jpg'  => 'jpg',
            'image/png'  => 'png'
        ];

        $allowed_videos = [
            'video/mp4'  => 'mp4',
            'video/webm' => 'webm'
        ];

        $ext = '';
        if (array_key_exists($mime_type, $allowed_images)) {
            $media_type = 'image';
            $ext = $allowed_images[$mime_type];
        } elseif (array_key_exists($mime_type, $allowed_videos)) {
            $media_type = 'video';
            $ext = $allowed_videos[$mime_type];
        } else {
            // Also inspect original extension in case MIME detection provides application/octet-stream
            $orig_ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (in_array($orig_ext, ['jpg', 'jpeg', 'png'])) {
                $media_type = 'image';
                $ext = ($orig_ext === 'jpeg') ? 'jpg' : $orig_ext;
            } elseif (in_array($orig_ext, ['mp4', 'webm'])) {
                $media_type = 'video';
                $ext = $orig_ext;
            } else {
                echo json_encode(['success' => false, 'error' => 'Invalid file format. Only JPG, PNG, MP4, and WEBM allowed.']);
                exit;
            }
        }

        $unique_filename = uniqid('media_', true) . '.' . $ext;
        $destination = $upload_dir . $unique_filename;

        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            echo json_encode(['success' => false, 'error' => 'Failed to move uploaded media file.']);
            exit;
        }

        $media_path = 'uploads/media/' . $unique_filename;
    }

    if (empty($text_content) && empty($media_path)) {
        echo json_encode(['success' => false, 'error' => 'Post cannot be completely empty. Add some text or upload media.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO posts (user_id, text_content, media_path, media_type) VALUES (?, ?, ?, ?)");
        $stmt->execute([$current_user_id, $text_content, $media_path, $media_type]);
        $post_id = $pdo->lastInsertId();

        // Fetch user data for instant dynamic rendering
        $user_stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
        $user_stmt->execute([$current_user_id]);
        $author = $user_stmt->fetch();

        echo json_encode([
            'success' => true,
            'post' => [
                'id' => $post_id,
                'user_id' => $current_user_id,
                'username' => $author['username'],
                'profile_picture' => $author['profile_picture'] ?: 'default_avatar.png',
                'text_content' => $text_content,
                'media_path' => $media_path,
                'media_type' => $media_type,
                'created_at' => 'Just now',
                'likes_count' => 0,
                'has_liked' => false,
                'comments_count' => 0,
                'comments' => []
            ]
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit;
    }
}

// ACTION 2: TOGGLE LIKE
if ($action === 'toggle_like') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    if ($post_id <= 0) {
        echo json_encode(['success' => false, 'error' => 'Invalid post ID.']);
        exit;
    }

    try {
        $chk = $pdo->prepare("SELECT 1 FROM likes WHERE user_id = ? AND post_id = ?");
        $chk->execute([$current_user_id, $post_id]);
        $exists = $chk->fetch();

        if ($exists) {
            $del = $pdo->prepare("DELETE FROM likes WHERE user_id = ? AND post_id = ?");
            $del->execute([$current_user_id, $post_id]);
            $liked = false;
        } else {
            $ins = $pdo->prepare("INSERT INTO likes (user_id, post_id) VALUES (?, ?)");
            $ins->execute([$current_user_id, $post_id]);
            $liked = true;
        }

        $cnt = $pdo->prepare("SELECT COUNT(*) AS total FROM likes WHERE post_id = ?");
        $cnt->execute([$post_id]);
        $like_count = (int)$cnt->fetch()['total'];

        echo json_encode([
            'success' => true,
            'liked' => $liked,
            'like_count' => $like_count,
            'post_id' => $post_id
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to toggle like: ' . $e->getMessage()]);
        exit;
    }
}

// ACTION 3: ADD COMMENT
if ($action === 'add_comment') {
    $post_id = (int)($_POST['post_id'] ?? 0);
    $text_content = trim($_POST['text_content'] ?? '');

    if ($post_id <= 0 || empty($text_content)) {
        echo json_encode(['success' => false, 'error' => 'Comment cannot be blank.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO comments (post_id, user_id, text_content) VALUES (?, ?, ?)");
        $stmt->execute([$post_id, $current_user_id, $text_content]);
        $comment_id = $pdo->lastInsertId();

        $author_stmt = $pdo->prepare("SELECT username, profile_picture FROM users WHERE id = ?");
        $author_stmt->execute([$current_user_id]);
        $author = $author_stmt->fetch();

        echo json_encode([
            'success' => true,
            'comment' => [
                'id' => $comment_id,
                'post_id' => $post_id,
                'username' => $author['username'],
                'profile_picture' => $author['profile_picture'] ?: 'default_avatar.png',
                'text_content' => $text_content,
                'created_at' => 'Just now'
            ]
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Failed to save comment: ' . $e->getMessage()]);
        exit;
    }
}

// ACTION 4: TOGGLE FOLLOW
if ($action === 'toggle_follow') {
    $target_user_id = (int)($_POST['target_user_id'] ?? 0);

    if ($target_user_id <= 0 || $target_user_id === $current_user_id) {
        echo json_encode(['success' => false, 'error' => 'Invalid user to follow.']);
        exit;
    }

    try {
        $chk = $pdo->prepare("SELECT 1 FROM followers WHERE follower_id = ? AND following_id = ?");
        $chk->execute([$current_user_id, $target_user_id]);
        $is_following = (bool)$chk->fetch();

        if ($is_following) {
            $del = $pdo->prepare("DELETE FROM followers WHERE follower_id = ? AND following_id = ?");
            $del->execute([$current_user_id, $target_user_id]);
            $now_following = false;
        } else {
            $ins = $pdo->prepare("INSERT INTO followers (follower_id, following_id) VALUES (?, ?)");
            $ins->execute([$current_user_id, $target_user_id]);
            $now_following = true;
        }

        // Count followers of target user
        $cnt = $pdo->prepare("SELECT COUNT(*) AS total FROM followers WHERE following_id = ?");
        $cnt->execute([$target_user_id]);
        $followers_count = (int)$cnt->fetch()['total'];

        echo json_encode([
            'success' => true,
            'is_following' => $now_following,
            'followers_count' => $followers_count,
            'target_user_id' => $target_user_id
        ]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'Follow toggle error: ' . $e->getMessage()]);
        exit;
    }
}

// ACTION 5: UPDATE PROFILE
if ($action === 'update_profile') {
    $bio = trim($_POST['bio'] ?? '');
    $profile_picture = null;

    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        $pic = $_FILES['profile_picture'];
        if ($pic['error'] === UPLOAD_ERR_OK) {
            $allowed_types = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/jpg' => 'jpg'];
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($pic['tmp_name']);

            if (isset($allowed_types[$mime])) {
                $filename = 'avatar_' . $current_user_id . '_' . time() . '.' . $allowed_types[$mime];
                $dest = $upload_dir . $filename;
                if (move_uploaded_file($pic['tmp_name'], $dest)) {
                    $profile_picture = 'uploads/media/' . $filename;
                }
            }
        }
    }

    try {
        if ($profile_picture) {
            $update = $pdo->prepare("UPDATE users SET bio = ?, profile_picture = ? WHERE id = ?");
            $update->execute([$bio, $profile_picture, $current_user_id]);
        } else {
            $update = $pdo->prepare("UPDATE users SET bio = ? WHERE id = ?");
            $update->execute([$bio, $current_user_id]);
        }
        header("Location: profile.php?user_id=" . $current_user_id);
        exit;
    } catch (PDOException $e) {
        header("Location: profile.php?user_id=" . $current_user_id . "&error=" . urlencode($e->getMessage()));
        exit;
    }
}

echo json_encode(['success' => false, 'error' => 'Unknown action.']);
exit;
