<?php
// ADMIN AUTHENTICATION with Cookies
$admin_password = 'INSERTYOURMASTERPASSWORDHERE'; // Change this in production!
$admin_cookie_name = 'adm_token';
$admin_token_secret = 'your-admin-secret-key-change-this';

$authenticated = false;
$auth_error = null;

// Check logout
if (isset($_GET['logout'])) {
    setcookie($admin_cookie_name, '', time() - 3600, '/');
    header("Location: adm.php");
    exit;
}

// Check if already authenticated via cookie
if (isset($_COOKIE[$admin_cookie_name])) {
    $expected_token = hash_hmac('sha256', $admin_password, $admin_token_secret);
    if ($_COOKIE[$admin_cookie_name] === $expected_token) {
        $authenticated = true;
    }
}

// Check login attempt
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    if ($_POST['admin_password'] === $admin_password) {
        $token = hash_hmac('sha256', $admin_password, $admin_token_secret);
        setcookie($admin_cookie_name, $token, time() + (30 * 24 * 60 * 60), '/'); // 30 days
        $authenticated = true;
    } else {
        $auth_error = "Invalid password";
    }
}

if (!$authenticated) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Panel</title>
        <style>
            * { margin: 0; padding: 0; box-sizing: border-box; }
            body { 
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: #f5f5f5;
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                padding: 20px;
            }
            .login-box {
                background: white;
                padding: 30px;
                border-radius: 8px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
                width: 100%;
                max-width: 300px;
            }
            h1 {
                font-size: 24px;
                margin-bottom: 20px;
                color: #333;
            }
            input {
                width: 100%;
                padding: 10px;
                margin-bottom: 15px;
                border: 1px solid #ddd;
                border-radius: 4px;
                font-size: 14px;
            }
            button {
                width: 100%;
                padding: 10px;
                background: #dc3545;
                color: white;
                border: none;
                border-radius: 4px;
                font-size: 14px;
                font-weight: 500;
                cursor: pointer;
            }
            button:hover {
                background: #c82333;
            }
            .error {
                color: #dc3545;
                font-size: 13px;
                margin-bottom: 15px;
            }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>🔒 Admin Panel</h1>
            <?php if (isset($auth_error)): ?>
                <div class="error"><?php echo $auth_error; ?></div>
            <?php endif; ?>
            <form method="POST">
                <input type="password" name="admin_password" placeholder="Admin Password" required autofocus>
                <button type="submit" name="admin_login" value="1">Login</button>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Page variable (only accessible after authentication)
$page = $_GET['page'] ?? 'forum';

// Configuration and Cryptography
$app_key = 'your-super-secret-app-wide-key!';
$cipher = 'AES-256-CBC';
$iv = substr(hash('sha256', 'static-iv-for-single-file-prototype'), 0, 16);

// Database Helpers
function load_db($filename) {
    if (!file_exists($filename)) return [];
    return json_decode(file_get_contents($filename), true) ?: [];
}
function save_db($filename, $data) {
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
}
function load_jsonl($filename) {
    if (!file_exists($filename)) return [];

    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $data = [];

    foreach ($lines as $line) {
        $decoded = json_decode($line, true);
        if ($decoded) {
            $data[] = $decoded;
        }
    }

    // Sort by newest first
    usort($data, function($a, $b) {
        return ($b['unix_time'] ?? 0) <=> ($a['unix_time'] ?? 0);
    });

    return $data;
}

// Load all databases
$db_users = 'users.json';
$users_data = load_db($db_users);
if (empty($users_data)) $users_data = ['next_id' => 0, 'map' => [], 'reverse_map' => []];

$db_handles = 'handles.json';
$handles_data = load_db($db_handles);

// Handle Admin Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_action'])) {
    // Delete Thread
    if ($_POST['admin_action'] === 'delete_thread') {
        $db_forum = 'forum.json';
        $forum = load_db($db_forum);
        $t_id = $_POST['thread_id'];
        if (isset($forum[$t_id])) {
            unset($forum[$t_id]);
            save_db($db_forum, $forum);
        }
        header("Location: adm.php?page=forum");
        exit;
    }
    
    // Delete Post
    if ($_POST['admin_action'] === 'delete_post') {
        $db_forum = 'forum.json';
        $forum = load_db($db_forum);
        $t_id = $_POST['thread_id'];
        $p_idx = (int)$_POST['post_index'];
        if (isset($forum[$t_id]['posts'][$p_idx])) {
            array_splice($forum[$t_id]['posts'], $p_idx, 1);
            if (empty($forum[$t_id]['posts'])) {
                unset($forum[$t_id]);
            }
            save_db($db_forum, $forum);
        }
        header("Location: adm.php?page=thread&id=$t_id");
        exit;
    }

    // Delete Blog
    if ($_POST['admin_action'] === 'delete_blog') {
        $db_blogs = 'blogs.json';
        $blogs = load_db($db_blogs);
        $b_id = $_POST['blog_id'];
        if (isset($blogs[$b_id])) {
            unset($blogs[$b_id]);
            save_db($db_blogs, $blogs);
        }
        header("Location: adm.php?page=blogs");
        exit;
    }
    
    // Delete Blog Post (Comment)
    if ($_POST['admin_action'] === 'delete_blog_post') {
        $db_blogs = 'blogs.json';
        $blogs = load_db($db_blogs);
        $b_id = $_POST['blog_id'];
        $p_idx = (int)$_POST['post_index'];
        if (isset($blogs[$b_id]['posts'][$p_idx])) {
            array_splice($blogs[$b_id]['posts'], $p_idx, 1);
            if (empty($blogs[$b_id]['posts'])) {
                unset($blogs[$b_id]);
            }
            save_db($db_blogs, $blogs);
        }
        header("Location: adm.php?page=blogpost&id=$b_id");
        exit;
    }
    
    // Delete Item
    if ($_POST['admin_action'] === 'delete_item') {
        $db_items = 'data_items.json';
        $items = load_db($db_items);
        $i_id = $_POST['item_id'];
        if (isset($items[$i_id])) {
            unset($items[$i_id]);
            save_db($db_items, $items);
            // Also delete related statements
            $db_statements = 'data_statements.json';
            $statements = load_db($db_statements);
            if (isset($statements[$i_id])) {
                unset($statements[$i_id]);
                save_db($db_statements, $statements);
            }
        }
        header("Location: adm.php?page=data");
        exit;
    }
    
    // Delete Property
    if ($_POST['admin_action'] === 'delete_property') {
        $db_props = 'data_props.json';
        $props = load_db($db_props);
        $p_id = $_POST['property_id'];
        if (isset($props[$p_id])) {
            unset($props[$p_id]);
            save_db($db_props, $props);
            // Also remove this property from all statements
            $db_statements = 'data_statements.json';
            $statements = load_db($db_statements);
            foreach ($statements as $item_id => $item_statements) {
                $statements[$item_id] = array_values(array_filter($item_statements, function($st) use ($p_id) {
                    return $st['property'] !== $p_id;
                }));
            }
            save_db($db_statements, $statements);
        }
        header("Location: adm.php?page=data");
        exit;
    }
}

function get_handle($id) {
    global $handles_data;
    return isset($handles_data[$id]) ? htmlspecialchars($handles_data[$id]) : "Anonymous_$id";
}

function get_enc_uuid($id) {
    global $users_data;
    return $users_data['reverse_map'][$id] ?? 'unknown';
}

function get_value_link($value_id, $value_label) {
    if (strpos($value_id, 'Q') === 0) {
        return '<a href="https://www.wikidata.org/wiki/' . htmlspecialchars($value_id) . '" target="_blank">' . htmlspecialchars($value_label) . '</a>';
    } elseif (strpos($value_id, 'L') === 0) {
        return '<a href="adm.php?page=item&id=' . htmlspecialchars($value_id) . '">' . htmlspecialchars($value_label) . '</a>';
    } elseif (filter_var($value_id, FILTER_VALIDATE_URL)) {
        return '<a href="' . htmlspecialchars($value_id) . '" target="_blank">' . htmlspecialchars($value_label) . '</a>';
    } elseif (filter_var($value_label, FILTER_VALIDATE_URL)) {
        return '<a href="' . htmlspecialchars($value_label) . '" target="_blank">' . htmlspecialchars($value_label) . '</a>';
    } else {
        return htmlspecialchars($value_label);
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f5f5f5; 
            color: #333;
            line-height: 1.5;
        }
        
        .container { 
            max-width: 100%;
            height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        header { 
            background: #dc3545;
            color: white;
            border-bottom: 1px solid #c82333;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        header > div:first-child {
            font-size: 13px;
            white-space: nowrap;
            font-weight: 500;
        }
        
        header strong {
            font-weight: 600;
        }
        
        nav {
            display: flex;
            gap: 8px;
            align-items: center;
        }
        
        nav a {
            padding: 8px 12px;
            text-decoration: none;
            color: white;
            font-size: 14px;
            font-weight: 500;
            border-radius: 4px;
            transition: background 0.2s;
        }
        
        nav a:hover {
            background: rgba(255,255,255,0.2);
        }
        
        .logout-btn {
            padding: 6px 10px !important;
            background: rgba(0,0,0,0.2) !important;
            font-size: 12px !important;
        }
        
        main {
            flex: 1;
            overflow-y: auto;
            padding: 16px;
        }
        
        h2 {
            font-size: 20px;
            margin-bottom: 12px;
            color: #222;
        }
        
        h3 {
            font-size: 16px;
            margin-bottom: 10px;
            color: #333;
        }
        
        .box {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 12px;
            transition: box-shadow 0.2s;
        }
        
        .box:hover {
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
        }
        
        .forum-thread {
            background: white;
            border: 1px solid #e0e0e0;
            border-radius: 4px;
            margin-bottom: 10px;
            overflow: hidden;
            transition: all 0.2s;
        }
        
        .forum-thread:hover {
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .forum-thread-header {
            padding: 10px 12px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: baseline;
            gap: 8px;
        }
        
        .forum-thread-title {
            font-weight: 600;
            color: #0066cc;
            text-decoration: none;
            flex: 1;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        .forum-thread-title:hover {
            text-decoration: underline;
        }
        
        .forum-thread-meta {
            font-size: 12px;
            color: #999;
            white-space: nowrap;
        }
        
        .post {
            padding: 10px 12px;
            border-bottom: 1px solid #f5f5f5;
            position: relative;
        }
        
        .post:last-child {
            border-bottom: none;
        }
        
        .post-header {
            font-size: 13px;
            margin-bottom: 6px;
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .post-content {
            font-size: 14px;
            color: #333;
            word-break: break-word;
            margin-bottom: 8px;
        }
        
        .delete-btn {
            background: #dc3545 !important;
            padding: 4px 8px !important;
            font-size: 11px !important;
        }
        
        .delete-btn:hover {
            background: #c82333 !important;
        }
        
        .data-item {
            padding: 8px;
            border-bottom: 1px solid #f0f0f0;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 10px;
        }
        
        .data-item:last-child {
            border-bottom: none;
        }
        
        .data-item-left {
            flex: 1;
            min-width: 0;
        }
        
        .data-item-link {
            color: #0066cc;
            text-decoration: none;
            font-weight: 500;
        }
        
        .data-item-link:hover {
            text-decoration: underline;
        }
        
        .data-item-desc {
            color: #999;
            font-size: 12px;
        }
        
        .statement-item {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            padding: 10px;
            border-bottom: 1px solid #f0f0f0;
            gap: 10px;
        }
        
        .statement-content {
            flex: 1;
        }
        
        .statement-property {
            font-size: 13px;
            font-weight: 500;
            color: #666;
            margin-bottom: 4px;
        }
        
        .statement-value {
            font-size: 14px;
            color: #333;
            word-break: break-word;
        }
        
        .statement-value a {
            color: #0066cc;
            text-decoration: none;
        }
        
        .statement-value a:hover {
            text-decoration: underline;
        }
        
        button {
            padding: 8px 14px;
            background: #0066cc;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        button:hover {
            background: #0052a3;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-family: inherit;
            font-size: 14px;
            margin-bottom: 8px;
        }
        
        .handle {
            color: #d9534f;
            font-weight: 600;
            cursor: pointer;
        }
        
        @media (max-width: 640px) {
            header {
                padding: 10px;
                flex-direction: column;
                align-items: flex-start;
            }
            
            nav {
                width: 100%;
            }
            
            nav a {
                flex: 1;
                text-align: center;
                font-size: 12px;
            }
            
            main {
                padding: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <div>
                🔒 <strong>ADMIN PANEL</strong>
            </div>
            <nav>
                <a href="adm.php?page=forum">Forum</a>
                <a href="adm.php?page=blogs">Blogs</a>
                <a href="adm.php?page=data">Wiki</a>
                <a href="adm.php?page=deleted_statements">Deleted Statements</a>
                <a href="index.php" style="background: rgba(0,0,0,0.2);">← Back to App</a>
                <a href="?logout=1" class="logout-btn">Logout</a>
            </nav>
        </header>

        <main>
        <?php if ($page === 'forum'): ?>
            <h2>🗑️ Forum Management</h2>
            <?php 
            $forum = load_db('forum.json');
            if (empty($forum)): ?>
                <p style="text-align: center; color: #999; font-size: 14px; padding: 20px;">No threads.</p>
            <?php else:
                foreach (array_reverse($forum, true) as $t_id => $thread): ?>
                    <div class="forum-thread">
                        <div class="forum-thread-header">
                            <a href="adm.php?page=thread&id=<?php echo $t_id; ?>" class="forum-thread-title"><?php echo htmlspecialchars($thread['title']); ?></a>
                            <span class="forum-thread-meta"><?php echo count($thread['posts']); ?> posts</span>
                            <form method="POST" style="display:inline; margin: 0;">
                                <input type="hidden" name="admin_action" value="delete_thread">
                                <input type="hidden" name="thread_id" value="<?php echo $t_id; ?>">
                                <button class="delete-btn" onclick="return confirm('Delete entire thread?');">Delete Thread</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach;
            endif; ?>

        <?php elseif ($page === 'thread'): ?>
            <?php 
            $t_id = $_GET['id'];
            $forum = load_db('forum.json');
            $thread = $forum[$t_id] ?? null;
            if (!$thread) die("Thread not found.");
            ?>
            <h2><?php echo htmlspecialchars($thread['title']); ?></h2>
            <div style="margin-bottom: 12px;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="admin_action" value="delete_thread">
                    <input type="hidden" name="thread_id" value="<?php echo $t_id; ?>">
                    <button class="delete-btn" onclick="return confirm('Delete entire thread?');">🗑️ Delete Entire Thread</button>
                </form>
            </div>

            <div class="box">
                <h3><?php echo count($thread['posts']); ?> Posts</h3>
                <?php foreach ($thread['posts'] as $p_idx => $post): ?>
                    <div class="post">
                        <div class="post-header">
                            <span class="handle"><?php echo get_handle($post['author']); ?></span>
                            <span style="color: #999; font-size: 12px;">ID: <?php echo htmlspecialchars($post['author']); ?></span>
                            <span style="color: #999; font-size: 12px;">· <?php echo date('M j, H:i', $post['time']); ?></span>
                        </div>
                        <div class="post-content"><?php echo nl2br(htmlspecialchars($post['content'])); ?></div>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="admin_action" value="delete_post">
                            <input type="hidden" name="thread_id" value="<?php echo $t_id; ?>">
                            <input type="hidden" name="post_index" value="<?php echo $p_idx; ?>">
                            <button class="delete-btn" onclick="return confirm('Delete this post?');">Delete Post</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($page === 'blogs'): ?>
            <h2>🗑️ Blogs Management</h2>
            <?php 
            $blogs = load_db('blogs.json');
            if (empty($blogs)): ?>
                <p style="text-align: center; color: #999; font-size: 14px; padding: 20px;">No blogs.</p>
            <?php else:
                foreach (array_reverse($blogs, true) as $b_id => $blog): ?>
                    <div class="forum-thread">
                        <div class="forum-thread-header">
                            <a href="adm.php?page=blogpost&id=<?php echo $b_id; ?>" class="forum-thread-title"><?php echo htmlspecialchars($blog['title']); ?></a>
                            <span class="forum-thread-meta"><?php echo count($blog['posts']); ?> posts</span>
                            <form method="POST" style="display:inline; margin: 0;">
                                <input type="hidden" name="admin_action" value="delete_blog">
                                <input type="hidden" name="blog_id" value="<?php echo $b_id; ?>">
                                <button class="delete-btn" onclick="return confirm('Delete entire blog?');">Delete Blog</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach;
            endif; ?>

        <?php elseif ($page === 'blogpost'): ?>
            <?php 
            $b_id = $_GET['id'];
            $blogs = load_db('blogs.json');
            $blog = $blogs[$b_id] ?? null;
            if (!$blog) die("Blog not found.");
            ?>
            <h2><?php echo htmlspecialchars($blog['title']); ?></h2>
            <div style="margin-bottom: 12px;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="admin_action" value="delete_blog">
                    <input type="hidden" name="blog_id" value="<?php echo $b_id; ?>">
                    <button class="delete-btn" onclick="return confirm('Delete entire blog?');">🗑️ Delete Entire Blog</button>
                </form>
            </div>

            <div class="box">
                <h3><?php echo count($blog['posts']); ?> Posts</h3>
                <?php foreach ($blog['posts'] as $p_idx => $post): ?>
                    <div class="post">
                        <div class="post-header">
                            <span class="handle"><?php echo get_handle($post['author']); ?></span>
                            <span style="color: #999; font-size: 12px;">ID: <?php echo htmlspecialchars($post['author']); ?></span>
                            <span style="color: #999; font-size: 12px;">· <?php echo date('M j, H:i', $post['time']); ?></span>
                        </div>
                        <div class="post-content"><?php echo nl2br(htmlspecialchars($post['content'])); ?></div>
                        <form method="POST" style="display:inline;">
                            <input type="hidden" name="admin_action" value="delete_blog_post">
                            <input type="hidden" name="blog_id" value="<?php echo $b_id; ?>">
                            <input type="hidden" name="post_index" value="<?php echo $p_idx; ?>">
                            <button class="delete-btn" onclick="return confirm('Delete this post?');">Delete Post</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>

        <?php elseif ($page === 'data'): ?>
            <h2>🗑️ Wiki Management</h2>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-bottom: 16px;">
                <div class="box">
                    <h3>Items (<?php echo count(load_db('data_items.json')); ?>)</h3>
                    <?php 
                    $items = load_db('data_items.json');
                    if (empty($items)): ?>
                        <p style="font-size: 13px; color: #999;">No items.</p>
                    <?php else:
                        foreach ($items as $id => $item): ?>
                            <div class="data-item">
                                <div class="data-item-left">
                                    <a href="adm.php?page=item&id=<?php echo $id; ?>" class="data-item-link"><?php echo htmlspecialchars($item['label']); ?></a>
                                    <div class="data-item-desc"><?php echo htmlspecialchars($item['desc']); ?></div>
                                </div>
                                <form method="POST" style="display:inline; margin: 0;">
                                    <input type="hidden" name="admin_action" value="delete_item">
                                    <input type="hidden" name="item_id" value="<?php echo $id; ?>">
                                    <button class="delete-btn" onclick="return confirm('Delete this item?');">✕</button>
                                </form>
                            </div>
                        <?php endforeach;
                    endif; ?>
                </div>

                <div class="box">
                    <h3>Properties (<?php echo count(load_db('data_props.json')); ?>)</h3>
                    <?php 
                    $props = load_db('data_props.json');
                    if (empty($props)): ?>
                        <p style="font-size: 13px; color: #999;">No properties.</p>
                    <?php else:
                        foreach ($props as $p_id => $prop): ?>
                            <div class="data-item">
                                <div class="data-item-left">
                                    <div class="data-item-link"><?php echo htmlspecialchars($prop['label']); ?></div>
                                </div>
                                <form method="POST" style="display:inline; margin: 0;">
                                    <input type="hidden" name="admin_action" value="delete_property">
                                    <input type="hidden" name="property_id" value="<?php echo $p_id; ?>">
                                    <button class="delete-btn" onclick="return confirm('Delete this property?');">✕</button>
                                </form>
                            </div>
                        <?php endforeach;
                    endif; ?>
                </div>
            </div>

        <?php elseif ($page === 'deleted_statements'): ?>
            <h2>🗑️ Deleted Statements Dashboard</h2>

            <?php
            $deletions = load_jsonl('deletion_log.jsonl');
            ?>

            <div class="box">
                <h3>
                    Deleted Statements
                    (<?php echo count($deletions); ?>)
                </h3>

                <?php if (empty($deletions)): ?>
                    <p style="color:#999;font-size:13px;">
                        No deletion logs yet.
                    </p>

                <?php else: ?>

                    <?php foreach ($deletions as $log): ?>

                        <div class="data-item"
                             style="align-items:flex-start;
                                    flex-direction:column;
                                    gap:4px;
                                    padding:12px;">

                            <div style="font-size:13px;">
                                <strong>
                                    <?php echo htmlspecialchars($log['user_handle']); ?>
                                </strong>

                                (ID:
                                <?php echo htmlspecialchars($log['user_id']); ?>)

                                deleted a statement
                            </div>

                            <div style="font-size:12px;color:#888;">
                                <?php
                                echo date(
                                    'Y-m-d H:i:s',
                                    $log['unix_time']
                                );
                                ?>
                            </div>

                            <div style="
                                background:#f8f8f8;
                                border-left:3px solid #dc3545;
                                padding:8px;
                                width:100%;
                                font-size:14px;
                            ">

                                <div>
                                    <strong>Item:</strong>

                                    <a href="adm.php?page=item&id=<?php echo urlencode($log['triple']['item_id']); ?>">
                                        <?php echo htmlspecialchars($log['triple']['item_label']); ?>
                                    </a>

                                    (<?php echo htmlspecialchars($log['triple']['item_id']); ?>)
                                </div>

                                <div>
                                    <strong>Property:</strong>

                                    <?php
                                    echo htmlspecialchars(
                                        $log['triple']['property_label']
                                    );
                                    ?>

                                    (<?php
                                    echo htmlspecialchars(
                                        $log['triple']['property_id']
                                    );
                                    ?>)
                                </div>

                                <div>
                                    <strong>Value:</strong>

                                    <?php
                                    echo get_value_link(
                                        $log['triple']['value_id'],
                                        $log['triple']['value_label']
                                    );
                                    ?>
                                </div>

                            </div>
                        </div>

                    <?php endforeach; ?>

                <?php endif; ?>
            </div>

        <?php elseif ($page === 'item'): ?>
            <?php 
            $i_id = $_GET['id'];
            $items = load_db('data_items.json');
            $props = load_db('data_props.json');
            $statements = load_db('data_statements.json')[$i_id] ?? [];
            $item = $items[$i_id] ?? null;
            if (!$item) die("Item not found.");
            ?>
            <h2><?php echo htmlspecialchars($item['label']); ?></h2>
            <p style="color: #666; margin-bottom: 12px;"><?php echo htmlspecialchars($item['desc']); ?></p>
            
            <div style="margin-bottom: 12px;">
                <form method="POST" style="display:inline;">
                    <input type="hidden" name="admin_action" value="delete_item">
                    <input type="hidden" name="item_id" value="<?php echo $i_id; ?>">
                    <button class="delete-btn" onclick="return confirm('Delete this item?');">🗑️ Delete Item</button>
                </form>
            </div>

            <div class="box">
                <h3>Statements (<?php echo count($statements); ?>)</h3>
                <?php if (empty($statements)): ?>
                    <p style="font-size: 13px; color: #999;">No statements.</p>
                <?php else:
                    foreach ($statements as $stmt_idx => $st): ?>
                        <div class="statement-item">
                            <div class="statement-content">
                                <div class="statement-property"><?php echo htmlspecialchars($props[$st['property']]['label'] ?? $st['property']); ?></div>
                                <div class="statement-value"><?php echo get_value_link($st['value_id'], $st['value_label']); ?></div>
                            </div>
                        </div>
                    <?php endforeach;
                endif; ?>
            </div>
        <?php endif; ?>
        </main>
    </div>
</body>
</html>