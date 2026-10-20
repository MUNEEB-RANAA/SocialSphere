<?php
// profile.php - User profile, followers/following, stats, and personal posts grid/feed
require_once __DIR__ . '/auth.php';
require_login();

$target_user_id = isset($_GET['user_id']) ? (int)$_GET['user_id'] : (int)$_SESSION['user_id'];
$current_user_id = (int)$_SESSION['user_id'];

// Fetch profile user
$stmt = $pdo->prepare("SELECT id, username, email, profile_picture, bio, created_at FROM users WHERE id = ?");
$stmt->execute([$target_user_id]);
$profile_user = $stmt->fetch();

if (!$profile_user) {
    die("User profile not found. <a href='index.php'>Return to Feed</a>");
}

$is_own_profile = ($target_user_id === $current_user_id);

// Follower & Following count
$follower_stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM followers WHERE following_id = ?");
$follower_stmt->execute([$target_user_id]);
$followers_count = (int)$follower_stmt->fetch()['total'];

$following_stmt = $pdo->prepare("SELECT COUNT(*) AS total FROM followers WHERE follower_id = ?");
$following_stmt->execute([$target_user_id]);
$following_count = (int)$following_stmt->fetch()['total'];

// Is current user following target user?
$is_following = false;
if (!$is_own_profile) {
    $chk_follow = $pdo->prepare("SELECT 1 FROM followers WHERE follower_id = ? AND following_id = ?");
    $chk_follow->execute([$current_user_id, $target_user_id]);
    $is_following = (bool)$chk_follow->fetch();
}

// Fetch user's posts
$post_stmt = $pdo->prepare("
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
    WHERE p.user_id = :target_user_id
    ORDER BY p.created_at DESC
");
$post_stmt->execute([
    'current_user_id' => $current_user_id,
    'target_user_id'  => $target_user_id
]);
$posts = $post_stmt->fetchAll();

// Fetch comments for these posts
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@<?= htmlspecialchars($profile_user['username']) ?> - FlowMate Profile</title>
    <link rel="stylesheet" href="style.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body data-user-id="<?= $current_user_id ?>">
    <!-- Top Nav -->
    <header class="top-nav">
        <div class="nav-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-symbol">🌊</span>
                <span class="logo-text">FlowMate</span>
            </a>
            <div class="nav-links">
                <a href="index.php" class="nav-item">Home Feed</a>
                <a href="profile.php?user_id=<?= $current_user_id ?>" class="nav-item <?= $is_own_profile ? 'active' : '' ?> user-pill">
                    <img src="<?= htmlspecialchars($_SESSION['user_id'] === $profile_user['id'] ? ($profile_user['profile_picture'] ?: 'default_avatar.png') : 'default_avatar.png') ?>" alt="Profile" class="avatar-small">
                    <span>@<?= htmlspecialchars($_SESSION['username']) ?></span>
                </a>
                <a href="logout.php" class="btn btn-secondary btn-sm">Log Out</a>
            </div>
        </div>
    </header>

    <main class="profile-layout">
        <!-- Hero Header -->
        <section class="profile-header-card glass-panel">
            <div class="profile-banner"></div>
            <div class="profile-identity">
                <div class="avatar-wrap">
                    <img src="<?= htmlspecialchars($profile_user['profile_picture'] ?: 'default_avatar.png') ?>" alt="Profile avatar" class="avatar-profile">
                </div>
                <div class="profile-titles">
                    <div class="title-row">
                        <h2>@<?= htmlspecialchars($profile_user['username']) ?></h2>
                        <?php if ($is_own_profile): ?>
                            <button type="button" class="btn btn-secondary btn-sm" id="editProfileToggleBtn">Edit Profile</button>
                        <?php else: ?>
                            <button type="button" class="btn btn-sm btn-follow follow-btn <?= $is_following ? 'following' : '' ?>" data-user-id="<?= (int)$profile_user['id'] ?>">
                                <?= $is_following ? 'Following' : 'Follow' ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <p class="joined-date">Member since <?= date("F Y", strtotime($profile_user['created_at'])) ?></p>
                </div>
            </div>

            <!-- Bio -->
            <div class="profile-bio-box">
                <p class="bio-text">
                    <?= !empty($profile_user['bio']) ? nl2br(htmlspecialchars($profile_user['bio'])) : '<em>No bio specified yet.</em>' ?>
                </p>
            </div>

            <!-- Stats Bar -->
            <div class="profile-metrics-bar">
                <div class="metric-item">
                    <span class="metric-val"><?= count($posts) ?></span>
                    <span class="metric-lbl">Posts</span>
                </div>
                <div class="metric-item">
                    <span class="metric-val" id="profileFollowersCount"><?= $followers_count ?></span>
                    <span class="metric-lbl">Followers</span>
                </div>
                <div class="metric-item">
                    <span class="metric-val"><?= $following_count ?></span>
                    <span class="metric-lbl">Following</span>
                </div>
            </div>

            <!-- Edit Profile Modal / Drawer -->
            <?php if ($is_own_profile): ?>
                <div id="editProfileDrawer" class="edit-drawer" style="display: none;">
                    <form action="post_action.php" method="POST" enctype="multipart/form-data" class="edit-profile-form">
                        <input type="hidden" name="action" value="update_profile">
                        <h4>Edit Profile Info</h4>
                        <div class="form-group">
                            <label for="bioInput">Bio</label>
                            <textarea id="bioInput" name="bio" rows="3" placeholder="Tell others about yourself..."><?= htmlspecialchars($profile_user['bio'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label for="profilePicInput">Profile Picture (JPG or PNG)</label>
                            <input type="file" id="profilePicInput" name="profile_picture" accept=".jpg,.jpeg,.png">
                        </div>
                        <div class="drawer-actions">
                            <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
                            <button type="button" class="btn btn-secondary btn-sm" id="cancelEditProfileBtn">Cancel</button>
                        </div>
                    </form>
                </div>
            <?php endif; ?>
        </section>

        <!-- User's Timeline / Grid -->
        <section class="profile-content-section">
            <h3 class="section-title">Posts by @<?= htmlspecialchars($profile_user['username']) ?></h3>

            <div id="feedContainer" class="feed-container">
                <?php if (empty($posts)): ?>
                    <div class="empty-state glass-panel">
                        <div class="empty-icon">📷</div>
                        <h3>No posts to show</h3>
                        <p>This user hasn't posted any updates yet.</p>
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
    </main>

    <script src="script.js"></script>
    <script>
        // Profile drawer toggle logic
        const editBtn = document.getElementById('editProfileToggleBtn');
        const cancelBtn = document.getElementById('cancelEditProfileBtn');
        const drawer = document.getElementById('editProfileDrawer');

        if (editBtn && drawer) {
            editBtn.addEventListener('click', () => {
                drawer.style.display = (drawer.style.display === 'none' || drawer.style.display === '') ? 'block' : 'none';
            });
        }
        if (cancelBtn && drawer) {
            cancelBtn.addEventListener('click', () => {
                drawer.style.display = 'none';
            });
        }
    </script>
</body>
</html>
