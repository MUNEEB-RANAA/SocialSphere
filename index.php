<?php
// index.php - Main dashboard and social timeline feed
require_once __DIR__ . '/auth.php';
require_login();

$current_user = get_current_user_data($pdo);

// Fetch timeline posts with author info, like count, whether current user liked, and comments
$query = "
    SELECT 
        p.id,
        p.user_id,
        p.text_content,
        p.media_path,
        p.media_type,
        p.created_at,
        u.username,
        u.profile_picture,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id) AS likes_count,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.id AND user_id = :current_user_id) AS has_liked
    FROM posts p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.created_at DESC
    LIMIT 100
";

$stmt = $pdo->prepare($query);
$stmt->execute(['current_user_id' => $_SESSION['user_id']]);
$posts = $stmt->fetchAll();

// Preload comments for these posts
$post_ids = array_column($posts, 'id');
$comments_by_post = [];

if (!empty($post_ids)) {
    $in_clause = implode(',', array_map('intval', $post_ids));
    $comment_query = "
        SELECT 
            c.id, c.post_id, c.user_id, c.text_content, c.created_at,
            u.username, u.profile_picture
        FROM comments c
        JOIN users u ON c.user_id = u.id
        WHERE c.post_id IN ($in_clause)
        ORDER BY c.created_at ASC
    ";
    $comment_stmt = $pdo->query($comment_query);
    while ($row = $comment_stmt->fetch()) {
        $comments_by_post[$row['post_id']][] = $row;
    }
}

// Recommended users to follow (excluding self)
$rec_stmt = $pdo->prepare("
    SELECT u.id, u.username, u.profile_picture,
           (SELECT COUNT(*) FROM followers WHERE follower_id = :follower_id AND following_id = u.id) AS is_following
    FROM users u 
    WHERE u.id != :exclude_id
    LIMIT 5
");
$rec_stmt->execute([
    'follower_id' => $_SESSION['user_id'],
    'exclude_id'  => $_SESSION['user_id']
]);
$suggested_users = $rec_stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FlowMate - Home Feed</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body data-user-id="<?= (int)$_SESSION['user_id'] ?>">
    <!-- Top Navigation Bar -->
    <header class="top-nav">
        <div class="nav-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-symbol">🌊</span>
                <span class="logo-text">FlowMate</span>
            </a>
            <div class="nav-links">
                <a href="index.php" class="nav-item active">Home Feed</a>
                <a href="profile.php?user_id=<?= (int)$_SESSION['user_id'] ?>" class="nav-item user-pill">
                    <img src="<?= htmlspecialchars($current_user['profile_picture'] ?: 'default_avatar.png') ?>" alt="Profile" class="avatar-small">
                    <span>@<?= htmlspecialchars($current_user['username']) ?></span>
                </a>
                <a href="logout.php" class="btn btn-secondary btn-sm">Log Out</a>
            </div>
        </div>
    </header>

    <main class="main-layout">
        <!-- Sidebar: User Snapshot & Navigation -->
        <aside class="sidebar-left">
            <div class="user-card glass-panel">
                <img src="<?= htmlspecialchars($current_user['profile_picture'] ?: 'default_avatar.png') ?>" alt="Profile" class="avatar-large">
                <h3 class="user-name">@<?= htmlspecialchars($current_user['username']) ?></h3>
                <p class="user-email"><?= htmlspecialchars($current_user['email']) ?></p>
                <?php if (!empty($current_user['bio'])): ?>
                    <p class="user-bio"><?= htmlspecialchars($current_user['bio']) ?></p>
                <?php endif; ?>
                <a href="profile.php?user_id=<?= (int)$_SESSION['user_id'] ?>" class="btn btn-primary btn-block btn-sm">View Full Profile</a>
            </div>
        </aside>

        <!-- Main Timeline Stream -->
        <section class="timeline-center">
            <!-- Create Post Card -->
            <div class="create-post-card glass-panel">
                <form id="createPostForm" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="create_post">
                    <div class="form-body">
                        <img src="<?= htmlspecialchars($current_user['profile_picture'] ?: 'default_avatar.png') ?>" alt="Avatar" class="avatar-mid">
                        <textarea name="text_content" id="postContent" placeholder="What's happening right now? Share your thoughts, images or videos..." rows="3"></textarea>
                    </div>

                    <!-- Media preview container -->
                    <div id="mediaPreviewContainer" class="media-preview-container" style="display: none;">
                        <button type="button" id="clearMediaBtn" class="clear-media-btn" title="Remove media">&times;</button>
                        <div id="previewWrapper"></div>
                    </div>

                    <!-- Validation warning box -->
                    <div id="clientErrorMsg" class="alert alert-error" style="display: none;"></div>

                    <div class="form-actions">
                        <label for="mediaFileInput" class="media-upload-label" title="Attach image (jpg, png) or video (mp4, webm)">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>
                            <span>Photo / Video</span>
                            <input type="file" id="mediaFileInput" name="media_file" accept=".jpg,.jpeg,.png,.mp4,.webm">
                        </label>
                        <div class="right-actions">
                            <span id="charCount" class="char-count">0/500</span>
                            <button type="submit" id="submitPostBtn" class="btn btn-primary">Post</button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Feed Stream -->
            <div class="feed-header">
                <h2>Latest Updates</h2>
                <span class="feed-badge">Live</span>
            </div>

            <div id="feedContainer" class="feed-container">
                <?php if (empty($posts)): ?>
                    <div class="empty-state glass-panel" id="emptyStateMsg">
                        <div class="empty-icon">💬</div>
                        <h3>No posts here yet</h3>
                        <p>Be the very first to break the silence and post something awesome!</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($posts as $post): ?>
                        <article class="post-card glass-panel" id="post-<?= (int)$post['id'] ?>" data-post-id="<?= (int)$post['id'] ?>">
                            <header class="post-header">
                                <a href="profile.php?user_id=<?= (int)$post['user_id'] ?>" class="author-info">
                                    <img src="<?= htmlspecialchars($post['profile_picture'] ?: 'default_avatar.png') ?>" alt="<?= htmlspecialchars($post['username']) ?>" class="avatar-mid">
                                    <div>
                                        <h4 class="post-author-name">@<?= htmlspecialchars($post['username']) ?></h4>
                                        <time class="post-time"><?= date("M j, Y • g:i a", strtotime($post['created_at'])) ?></time>
                                    </div>
                                </a>
                            </header>

                            <?php if (!empty($post['text_content'])): ?>
                                <div class="post-body">
                                    <p><?= nl2br(htmlspecialchars($post['text_content'])) ?></p>
                                </div>
                            <?php endif; ?>

                            <!-- Dynamic Media Rendering -->
                            <?php if ($post['media_type'] === 'image' && !empty($post['media_path'])): ?>
                                <div class="post-media-box">
                                    <img src="<?= htmlspecialchars($post['media_path']) ?>" alt="Post image" class="post-image" loading="lazy">
                                </div>
                            <?php elseif ($post['media_type'] === 'video' && !empty($post['media_path'])): ?>
                                <div class="post-media-box">
                                    <video controls preload="metadata" class="post-video" src="<?= htmlspecialchars($post['media_path']) ?>"></video>
                                </div>
                            <?php endif; ?>

                            <div class="post-stats">
                                <span class="like-counter"><strong class="count-num"><?= (int)$post['likes_count'] ?></strong> likes</span>
                                <span class="comment-counter"><strong class="count-num"><?= isset($comments_by_post[$post['id']]) ? count($comments_by_post[$post['id']]) : 0 ?></strong> comments</span>
                            </div>

                            <div class="post-actions-bar">
                                <button type="button" class="action-btn like-btn <?= $post['has_liked'] ? 'liked' : '' ?>" data-post-id="<?= (int)$post['id'] ?>">
                                    <svg class="heart-icon" viewBox="0 0 24 24" width="18" height="18"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                                    <span class="like-btn-label"><?= $post['has_liked'] ? 'Liked' : 'Like' ?></span>
                                </button>
                                <button type="button" class="action-btn toggle-comment-btn" data-post-id="<?= (int)$post['id'] ?>">
                                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                                    <span>Comment</span>
                                </button>
                            </div>

                            <!-- Comment Section -->
                            <div class="comment-section" id="comment-section-<?= (int)$post['id'] ?>">
                                <div class="comment-list" id="comment-list-<?= (int)$post['id'] ?>">
                                    <?php if (!empty($comments_by_post[$post['id']])): ?>
                                        <?php foreach ($comments_by_post[$post['id']] as $comment): ?>
                                            <div class="comment-bubble">
                                                <a href="profile.php?user_id=<?= (int)$comment['user_id'] ?>">
                                                    <img src="<?= htmlspecialchars($comment['profile_picture'] ?: 'default_avatar.png') ?>" alt="avatar" class="avatar-comment">
                                                </a>
                                                <div class="comment-content">
                                                    <div class="comment-meta">
                                                        <a href="profile.php?user_id=<?= (int)$comment['user_id'] ?>" class="comment-author">@<?= htmlspecialchars($comment['username']) ?></a>
                                                        <time class="comment-time"><?= date("M j, g:i a", strtotime($comment['created_at'])) ?></time>
                                                    </div>
                                                    <p class="comment-text"><?= nl2br(htmlspecialchars($comment['text_content'])) ?></p>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>

                                <form class="add-comment-form" data-post-id="<?= (int)$post['id'] ?>">
                                    <input type="text" name="text_content" class="comment-input" placeholder="Write a thoughtful comment..." autocomplete="off" required>
                                    <button type="submit" class="btn btn-sm btn-primary">Send</button>
                                </form>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Sidebar: Discover People -->
        <aside class="sidebar-right">
            <div class="glass-panel widget-panel">
                <h3>Discover Creators</h3>
                <div class="creators-list">
                    <?php if (empty($suggested_users)): ?>
                        <p class="text-muted">No other creators found yet.</p>
                    <?php else: ?>
                        <?php foreach ($suggested_users as $s_user): ?>
                            <div class="creator-item">
                                <a href="profile.php?user_id=<?= (int)$s_user['id'] ?>" class="creator-link">
                                    <img src="<?= htmlspecialchars($s_user['profile_picture'] ?: 'default_avatar.png') ?>" alt="Avatar" class="avatar-small">
                                    <span class="creator-name">@<?= htmlspecialchars($s_user['username']) ?></span>
                                </a>
                                <button type="button" class="btn btn-sm btn-follow follow-btn <?= $s_user['is_following'] ? 'following' : '' ?>" data-user-id="<?= (int)$s_user['id'] ?>">
                                    <?= $s_user['is_following'] ? 'Following' : 'Follow' ?>
                                </button>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </main>

    <script src="script.js"></script>
</body>
</html>
