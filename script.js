// script.js - AJAX handlers for media validation, posting, liking, commenting, and following

document.addEventListener('DOMContentLoaded', () => {
    initPostCreation();
    initLikeHandlers();
    initCommentHandlers();
    initFollowHandlers();
});

/**
 * 1. Post Creation & Media Validation Flow
 */
function initPostCreation() {
    const postForm = document.getElementById('createPostForm');
    const mediaInput = document.getElementById('mediaFileInput');
    const previewContainer = document.getElementById('mediaPreviewContainer');
    const previewWrapper = document.getElementById('previewWrapper');
    const clearMediaBtn = document.getElementById('clearMediaBtn');
    const errorMsg = document.getElementById('clientErrorMsg');
    const postText = document.getElementById('postContent');
    const charCount = document.getElementById('charCount');
    const submitBtn = document.getElementById('submitPostBtn');

    if (!postForm) return;

    // Character counter
    if (postText && charCount) {
        postText.addEventListener('input', () => {
            const length = postText.value.length;
            charCount.textContent = `${length}/500`;
            if (length > 500) {
                charCount.style.color = '#ef4444';
            } else {
                charCount.style.color = 'var(--text-muted)';
            }
        });
    }

    // Media file change & client-side validation
    if (mediaInput) {
        mediaInput.addEventListener('change', () => {
            errorMsg.style.display = 'none';
            errorMsg.textContent = '';
            previewWrapper.innerHTML = '';
            previewContainer.style.display = 'none';

            if (!mediaInput.files || mediaInput.files.length === 0) return;

            const file = mediaInput.files[0];
            const maxSizeBytes = 50 * 1024 * 1024; // 50MB
            const validImageTypes = ['image/jpeg', 'image/png', 'image/jpg'];
            const validVideoTypes = ['video/mp4', 'video/webm'];

            // Validate Size
            if (file.size > maxSizeBytes) {
                showError('File size exceeds the 50MB limit. Please choose a smaller file.');
                mediaInput.value = '';
                return;
            }

            // Validate Type
            const fileType = file.type.toLowerCase();
            const fileName = file.name.toLowerCase();
            const isImage = validImageTypes.includes(fileType) || fileName.endsWith('.jpg') || fileName.endsWith('.jpeg') || fileName.endsWith('.png');
            const isVideo = validVideoTypes.includes(fileType) || fileName.endsWith('.mp4') || fileName.endsWith('.webm');

            if (!isImage && !isVideo) {
                showError('Invalid file type! Only .jpg, .png, .mp4, and .webm files are allowed.');
                mediaInput.value = '';
                return;
            }

            // Preview rendering
            const objectUrl = URL.createObjectURL(file);
            if (isImage) {
                const img = document.createElement('img');
                img.src = objectUrl;
                img.alt = 'Upload preview';
                previewWrapper.appendChild(img);
            } else if (isVideo) {
                const video = document.createElement('video');
                video.src = objectUrl;
                video.controls = true;
                video.muted = true;
                previewWrapper.appendChild(video);
            }

            previewContainer.style.display = 'block';
        });
    }

    // Clear selected media button
    if (clearMediaBtn) {
        clearMediaBtn.addEventListener('click', () => {
            if (mediaInput) mediaInput.value = '';
            previewWrapper.innerHTML = '';
            previewContainer.style.display = 'none';
            errorMsg.style.display = 'none';
        });
    }

    // Form submission via Fetch API
    postForm.addEventListener('submit', async (e) => {
        e.preventDefault();

        const content = postText.value.trim();
        const hasMedia = mediaInput && mediaInput.files && mediaInput.files.length > 0;

        if (!content && !hasMedia) {
            showError('Please write some text or attach an image/video to post.');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Posting...';

        const formData = new FormData(postForm);

        try {
            const response = await fetch('post_action.php', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();

            if (result.success && result.post) {
                // Reset form state
                postForm.reset();
                previewWrapper.innerHTML = '';
                previewContainer.style.display = 'none';
                if (charCount) charCount.textContent = '0/500';
                errorMsg.style.display = 'none';

                // Prepend post to feed
                prependPostToFeed(result.post);

                const emptyState = document.getElementById('emptyStateMsg');
                if (emptyState) emptyState.remove();
            } else {
                showError(result.error || 'Failed to submit post. Please try again.');
            }
        } catch (err) {
            console.error('Post submission error:', err);
            showError('A network error occurred while posting.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Post';
        }
    });

    function showError(msg) {
        if (!errorMsg) return;
        errorMsg.textContent = msg;
        errorMsg.style.display = 'block';
    }
}

/**
 * 2. Helper to build DOM card and prepend to feed
 */
function prependPostToFeed(post) {
    const feed = document.getElementById('feedContainer');
    if (!feed) return;

    const article = document.createElement('article');
    article.className = 'post-card glass-panel';
    article.id = `post-${post.id}`;
    article.dataset.postId = post.id;

    let mediaHTML = '';
    if (post.media_type === 'image' && post.media_path) {
        mediaHTML = `
            <div class="post-media-box">
                <img src="${escapeHtml(post.media_path)}" alt="Post image" class="post-image" loading="lazy">
            </div>
        `;
    } else if (post.media_type === 'video' && post.media_path) {
        mediaHTML = `
            <div class="post-media-box">
                <video controls preload="metadata" class="post-video" src="${escapeHtml(post.media_path)}"></video>
            </div>
        `;
    }

    const textHTML = post.text_content ? `
        <div class="post-body">
            <p>${escapeHtml(post.text_content).replace(/\n/g, '<br>')}</p>
        </div>
    ` : '';

    article.innerHTML = `
        <header class="post-header">
            <a href="profile.php?user_id=${post.user_id}" class="author-info">
                <img src="${escapeHtml(post.profile_picture)}" alt="${escapeHtml(post.username)}" class="avatar-mid">
                <div>
                    <h4 class="post-author-name">@${escapeHtml(post.username)}</h4>
                    <time class="post-time">${escapeHtml(post.created_at)}</time>
                </div>
            </a>
        </header>

        ${textHTML}
        ${mediaHTML}

        <div class="post-stats">
            <span class="like-counter"><strong class="count-num">0</strong> likes</span>
            <span class="comment-counter"><strong class="count-num">0</strong> comments</span>
        </div>

        <div class="post-actions-bar">
            <button type="button" class="action-btn like-btn" data-post-id="${post.id}">
                <svg class="heart-icon" viewBox="0 0 24 24" width="18" height="18"><path d="M12 21.35l-1.45-1.32C5.4 15.36 2 12.28 2 8.5 2 5.42 4.42 3 7.5 3c1.74 0 3.41.81 4.5 2.09C13.09 3.81 14.76 3 16.5 3 19.58 3 22 5.42 22 8.5c0 3.78-3.4 6.86-8.55 11.54L12 21.35z"/></svg>
                <span class="like-btn-label">Like</span>
            </button>
            <button type="button" class="action-btn toggle-comment-btn" data-post-id="${post.id}">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/></svg>
                <span>Comment</span>
            </button>
        </div>

        <div class="comment-section" id="comment-section-${post.id}">
            <div class="comment-list" id="comment-list-${post.id}"></div>
            <form class="add-comment-form" data-post-id="${post.id}">
                <input type="text" name="text_content" class="comment-input" placeholder="Write a thoughtful comment..." autocomplete="off" required>
                <button type="submit" class="btn btn-sm btn-primary">Send</button>
            </form>
        </div>
    `;

    feed.prepend(article);
}

/**
 * 3. Like Interaction (Asynchronous via Fetch API)
 */
function initLikeHandlers() {
    document.addEventListener('click', async (e) => {
        const likeBtn = e.target.closest('.like-btn');
        if (!likeBtn) return;

        e.preventDefault();
        const postId = likeBtn.dataset.postId;
        if (!postId) return;

        try {
            const formData = new FormData();
            formData.append('action', 'toggle_like');
            formData.append('post_id', postId);

            const res = await fetch('post_action.php', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                const label = likeBtn.querySelector('.like-btn-label');
                if (data.liked) {
                    likeBtn.classList.add('liked');
                    if (label) label.textContent = 'Liked';
                } else {
                    likeBtn.classList.remove('liked');
                    if (label) label.textContent = 'Like';
                }

                // Update like counter
                const postCard = document.getElementById(`post-${postId}`);
                if (postCard) {
                    const counter = postCard.querySelector('.like-counter .count-num');
                    if (counter) counter.textContent = data.like_count;
                }
            }
        } catch (err) {
            console.error('Like toggle error:', err);
        }
    });
}

/**
 * 4. Comment Interaction (Asynchronous via Fetch API)
 */
function initCommentHandlers() {
    // Toggle comment focus/section
    document.addEventListener('click', (e) => {
        const toggleBtn = e.target.closest('.toggle-comment-btn');
        if (!toggleBtn) return;

        const postId = toggleBtn.dataset.postId;
        const postCard = document.getElementById(`post-${postId}`);
        if (postCard) {
            const input = postCard.querySelector('.comment-input');
            if (input) input.focus();
        }
    });

    // Form submit
    document.addEventListener('submit', async (e) => {
        const form = e.target.closest('.add-comment-form');
        if (!form) return;

        e.preventDefault();
        const postId = form.dataset.postId;
        const input = form.querySelector('.comment-input');
        const text = input.value.trim();

        if (!text) return;

        const submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'add_comment');
            formData.append('post_id', postId);
            formData.append('text_content', text);

            const res = await fetch('post_action.php', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success && data.comment) {
                input.value = '';
                appendCommentToPost(postId, data.comment);

                // Update counter
                const postCard = document.getElementById(`post-${postId}`);
                if (postCard) {
                    const counter = postCard.querySelector('.comment-counter .count-num');
                    if (counter) {
                        counter.textContent = parseInt(counter.textContent || 0, 10) + 1;
                    }
                }
            } else {
                alert(data.error || 'Failed to post comment.');
            }
        } catch (err) {
            console.error('Comment error:', err);
        } finally {
            if (submitBtn) submitBtn.disabled = false;
        }
    });
}

function appendCommentToPost(postId, comment) {
    const list = document.getElementById(`comment-list-${postId}`);
    if (!list) return;

    const bubble = document.createElement('div');
    bubble.className = 'comment-bubble';
    bubble.innerHTML = `
        <a href="profile.php?user_id=${comment.user_id || ''}">
            <img src="${escapeHtml(comment.profile_picture)}" alt="avatar" class="avatar-comment">
        </a>
        <div class="comment-content">
            <div class="comment-meta">
                <a href="profile.php?user_id=${comment.user_id || ''}" class="comment-author">@${escapeHtml(comment.username)}</a>
                <time class="comment-time">${escapeHtml(comment.created_at)}</time>
            </div>
            <p class="comment-text">${escapeHtml(comment.text_content).replace(/\n/g, '<br>')}</p>
        </div>
    `;
    list.appendChild(bubble);
}

/**
 * 5. Follow / Unfollow Interaction (Asynchronous via Fetch API)
 */
function initFollowHandlers() {
    document.addEventListener('click', async (e) => {
        const followBtn = e.target.closest('.follow-btn');
        if (!followBtn) return;

        e.preventDefault();
        const targetUserId = followBtn.dataset.userId;
        if (!targetUserId) return;

        followBtn.disabled = true;

        try {
            const formData = new FormData();
            formData.append('action', 'toggle_follow');
            formData.append('target_user_id', targetUserId);

            const res = await fetch('post_action.php', {
                method: 'POST',
                body: formData
            });

            const data = await res.json();
            if (data.success) {
                if (data.is_following) {
                    followBtn.classList.add('following');
                    followBtn.textContent = 'Following';
                } else {
                    followBtn.classList.remove('following');
                    followBtn.textContent = 'Follow';
                }

                // If on profile page and viewing this user, update follower count
                const profileFollowersCount = document.getElementById('profileFollowersCount');
                if (profileFollowersCount) {
                    profileFollowersCount.textContent = data.followers_count;
                }
            } else {
                alert(data.error || 'Follow action failed.');
            }
        } catch (err) {
            console.error('Follow error:', err);
        } finally {
            followBtn.disabled = false;
        }
    });
}

/**
 * Escape HTML to guard against XSS in DOM injection
 */
function escapeHtml(str) {
    if (!str) return '';
    return str
        .toString()
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
