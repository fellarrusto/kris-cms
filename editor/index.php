<?php
require_once '../core/entity/Entity.php';

$action = $_GET['action'] ?? 'groups';
$name = $_GET['name'] ?? null;

$file = __DIR__ . '/../data/k_data.json';
$data = json_decode(file_get_contents($file), true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'save') {
        foreach ($data as &$item) {
            if ($item['name'] === $_POST['name'] && $item['id'] == $_POST['id']) {
                foreach ($item['data'] as &$field) {
                    $fieldName = $field['name'];
                    if ($field['type'] === 'text' || $field['type'] === 'path') {
                        $field['value']['it'] = $_POST[$fieldName]['it'] ?? '';
                        $field['value']['en'] = $_POST[$fieldName]['en'] ?? '';
                    }
                }
            }
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: index.php?action=list&name={$_POST['name']}");
        exit;
    }
    
    if ($_POST['action'] === 'delete') {
        $data = array_filter($data, fn($item) => !($item['name'] === $_POST['name'] && $item['id'] == $_POST['id']));
        file_put_contents($file, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: index.php?action=list&name={$_POST['name']}");
        exit;
    }
    
    if ($_POST['action'] === 'add') {
        $maxId = -1;
        foreach ($data as $item) {
            if ($item['name'] === $_POST['name']) {
                $maxId = max($maxId, $item['id']);
            }
        }
        $template = null;
        foreach ($data as $item) {
            if ($item['name'] === $_POST['name']) {
                $template = $item;
                break;
            }
        }
        $newItem = $template;
        $newItem['id'] = $maxId + 1;
        foreach ($newItem['data'] as &$field) {
            if (is_array($field['value'])) {
                $field['value'] = ['it' => '', 'en' => ''];
            } else {
                $field['value'] = '';
            }
        }
        $data[] = $newItem;
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        header("Location: index.php?action=edit&name={$_POST['name']}&id={$newItem['id']}");
        exit;
    }
}

function getGroups($data) {
    $groups = [];
    foreach ($data as $item) {
        $groups[$item['name']] = ($groups[$item['name']] ?? 0) + 1;
    }
    return $groups;
}

function getEntitiesByName($data, $name) {
    return array_filter($data, fn($item) => $item['name'] === $name);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Kris Admin</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: system-ui; padding: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; }
        h1 { margin-bottom: 20px; }
        .groups { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; }
        .group-card { padding: 20px; border: 1px solid #ddd; border-radius: 6px; cursor: pointer; }
        .group-card:hover { background: #f9f9f9; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background: #f5f5f5; }
        button { padding: 8px 16px; border: none; border-radius: 4px; cursor: pointer; }
        .btn-primary { background: #007bff; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        input, textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; }
        .form-group { margin-bottom: 15px; }
        .lang-inputs { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
        .back { display: inline-block; margin-bottom: 20px; color: #007bff; text-decoration: none; }
    </style>
</head>
<body>
<div class="container">
    <?php if ($action === 'groups'): ?>
        <h1>Entity Groups</h1>
        <div class="groups">
            <?php foreach (getGroups($data) as $groupName => $count): ?>
                <a href="?action=list&name=<?= $groupName ?>" style="text-decoration: none; color: inherit;">
                    <div class="group-card">
                        <h3><?= $groupName ?></h3>
                        <p><?= $count ?> items</p>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    
    <?php elseif ($action === 'list'): ?>
        <a href="?action=groups" class="back">← Back</a>
        <h1><?= $name ?></h1>
        <form method="POST">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="name" value="<?= $name ?>">
            <button type="submit" class="btn-success">+ Add New</button>
        </form>
        <table>
            <tr>
                <th>ID</th>
                <th>Preview</th>
                <th>Actions</th>
            </tr>
            <?php foreach (getEntitiesByName($data, $name) as $entity): ?>
                <tr>
                    <td><?= $entity['id'] ?></td>
                    <td><?= $entity['data'][0]['value']['it'] ?? $entity['data'][0]['value'] ?? '-' ?></td>
                    <td>
                        <a href="?action=edit&name=<?= $name ?>&id=<?= $entity['id'] ?>">
                            <button class="btn-primary">Edit</button>
                        </a>
                        <form method="POST" style="display: inline;" onsubmit="return confirm('Sure?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="name" value="<?= $name ?>">
                            <input type="hidden" name="id" value="<?= $entity['id'] ?>">
                            <button class="btn-danger">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    
    <?php elseif ($action === 'edit'): ?>
        <?php
        $id = $_GET['id'];
        $entity = null;
        foreach ($data as $item) {
            if ($item['name'] === $name && $item['id'] == $id) {
                $entity = $item;
                break;
            }
        }
        ?>
        <a href="?action=list&name=<?= $name ?>" class="back">← Back</a>
        <h1>Edit <?= $name ?> #<?= $id ?></h1>
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="name" value="<?= $name ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            
            <?php foreach ($entity['data'] as $field): ?>
                <div class="form-group">
                    <label><strong><?= $field['name'] ?></strong> (<?= $field['type'] ?>)</label>
                    <?php if ($field['type'] === 'text' || $field['type'] === 'path'): ?>
                        <div class="lang-inputs">
                            <div>
                                <label>IT</label>
                                <textarea name="<?= $field['name'] ?>[it]" rows="3"><?= $field['value']['it'] ?? '' ?></textarea>
                            </div>
                            <div>
                                <label>EN</label>
                                <textarea name="<?= $field['name'] ?>[en]" rows="3"><?= $field['value']['en'] ?? '' ?></textarea>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            
            <button type="submit" class="btn-success">Save</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>