<?php
/**
 * Kris 2 - Single File CMS
 * Place this file in your /editor/ folder or root.
 */

// CONFIGURATION
$jsonPath = __DIR__ . '/../data/k_data.json'; // Adjust path if necessary
$languages = ['it', 'en'];

// ------------------------------------------------------------------
// BACKEND LOGIC
// ------------------------------------------------------------------

// Helper: Load Data
function loadData($path) {
    if (!file_exists($path)) return [];
    return json_decode(file_get_contents($path), true) ?? [];
}

// Helper: Save Data
function saveData($path, $data) {
    file_put_contents($path, json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

// Helper: Redirect
function redirect($url) {
    header("Location: $url");
    exit;
}

// Helper: Get Schema (Fields) from the first item of a group
function getGroupSchema($data, $groupName) {
    foreach ($data as $item) {
        if ($item['name'] === $groupName) return $item['data'];
    }
    return [];
}

$data = loadData($jsonPath);
$action = $_GET['action'] ?? 'dashboard';
$currentGroup = $_GET['group'] ?? null;
$currentId = $_GET['id'] ?? null;

// --- POST HANDLERS ---

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = $_POST['post_action'] ?? '';

    // 1. CREATE NEW GROUP (TYPE)
    if ($postAction === 'create_group') {
        $newGroupName = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['group_name']));
        if ($newGroupName) {
            // Create dummy structure
            $newItem = [
                'id' => 0,
                'name' => $newGroupName,
                'data' => [
                    ['name' => 'title', 'value' => ['it' => 'New Item', 'en' => 'New Item'], 'type' => 'text']
                ]
            ];
            $data[] = $newItem;
            saveData($jsonPath, $data);
            redirect("?action=structure&group=$newGroupName");
        }
    }

    // 2. UPDATE STRUCTURE (Add/Remove fields for a whole group)
    if ($postAction === 'update_structure') {
        $groupName = $_POST['group_name'];
        $newFields = [];
        
        // Rebuild field definitions from form
        if (isset($_POST['fields'])) {
            foreach ($_POST['fields'] as $f) {
                $newFields[] = [
                    'name' => $f['name'],
                    'type' => 'text', // Defaulting to text/json logic
                    'is_multi' => isset($f['is_multi'])
                ];
            }
        }

        // Update ALL entities in this group to match new structure
        foreach ($data as &$entity) {
            if ($entity['name'] === $groupName) {
                $updatedData = [];
                foreach ($newFields as $schemaField) {
                    $existingValue = null;
                    // Try to find existing value
                    foreach ($entity['data'] as $oldField) {
                        if ($oldField['name'] === $schemaField['name']) {
                            $existingValue = $oldField['value'];
                            break;
                        }
                    }

                    // Normalize Value based on Multilingual setting
                    if ($schemaField['is_multi']) {
                        if (!is_array($existingValue)) {
                            $existingValue = array_fill_keys($languages, $existingValue ?: '');
                        }
                    } else {
                        if (is_array($existingValue)) {
                            $existingValue = $existingValue[$languages[0]] ?? '';
                        }
                    }

                    $updatedData[] = [
                        'name' => $schemaField['name'],
                        'value' => $existingValue ?? ($schemaField['is_multi'] ? array_fill_keys($languages, '') : ''),
                        'type' => 'text'
                    ];
                }
                $entity['data'] = $updatedData;
            }
        }
        saveData($jsonPath, $data);
        redirect("?action=list&group=$groupName");
    }

    // 3. SAVE ENTITY CONTENT
    if ($postAction === 'save_entity') {
        $id = (int)$_POST['id'];
        $group = $_POST['group'];
        
        foreach ($data as &$entity) {
            if ($entity['name'] === $group && $entity['id'] === $id) {
                foreach ($entity['data'] as &$field) {
                    $fieldName = $field['name'];
                    if (isset($_POST[$fieldName])) {
                        // Check if it was originally array (multilang) or checks post structure
                        if (is_array($_POST[$fieldName])) {
                            $field['value'] = $_POST[$fieldName];
                        } else {
                            $field['value'] = $_POST[$fieldName];
                        }
                    }
                }
                break;
            }
        }
        saveData($jsonPath, $data);
        redirect("?action=list&group=$group");
    }

    // 4. ADD NEW ENTITY (Row)
    if ($postAction === 'add_entity') {
        $group = $_POST['group'];
        // Find schema from existing
        $template = null;
        $maxId = -1;
        foreach ($data as $entity) {
            if ($entity['name'] === $group) {
                $template = $entity;
                if ($entity['id'] > $maxId) $maxId = $entity['id'];
            }
        }

        if ($template) {
            $newEntity = $template;
            $newEntity['id'] = $maxId + 1;
            // Empty values
            foreach ($newEntity['data'] as &$field) {
                if (is_array($field['value'])) {
                    $field['value'] = array_fill_keys(array_keys($field['value']), '');
                } else {
                    $field['value'] = '';
                }
            }
            $data[] = $newEntity;
            saveData($jsonPath, $data);
            redirect("?action=edit&group=$group&id={$newEntity['id']}");
        }
    }

    // 5. DELETE ENTITY
    if ($postAction === 'delete_entity') {
        $group = $_POST['group'];
        $id = (int)$_POST['id'];
        $data = array_filter($data, function($item) use ($group, $id) {
            return !($item['name'] === $group && $item['id'] === $id);
        });
        saveData($jsonPath, $data);
        redirect("?action=list&group=$group");
    }

    // 6. DELETE GROUP
    if ($postAction === 'delete_group') {
        $group = $_POST['group'];
        $data = array_filter($data, function($item) use ($group) {
            return $item['name'] !== $group;
        });
        saveData($jsonPath, $data);
        redirect("?action=dashboard");
    }
}

// --- PREPARE VIEWS ---

$groups = [];
foreach ($data as $item) {
    if (!isset($groups[$item['name']])) $groups[$item['name']] = 0;
    $groups[$item['name']]++;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kris CMS</title>
    <style>
        :root { --primary: #2563eb; --bg: #f3f4f6; --card: #ffffff; --text: #1f2937; --danger: #ef4444; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--text); margin: 0; padding: 20px; }
        .container { max-width: 1000px; margin: 0 auto; }
        .card { background: var(--card); padding: 20px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-bottom: 20px; }
        h1, h2, h3 { margin-top: 0; }
        .btn { display: inline-block; padding: 8px 16px; background: var(--primary); color: white; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 14px; }
        .btn:hover { opacity: 0.9; }
        .btn-danger { background: var(--danger); }
        .btn-outline { background: transparent; border: 1px solid #ddd; color: var(--text); }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 20px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #eee; }
        th { font-weight: 600; color: #666; }
        input[type="text"], textarea, select { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-top: 4px; box-sizing: border-box; }
        .form-group { margin-bottom: 15px; }
        .nav-bar { margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; }
        .breadcrumb a { color: var(--primary); text-decoration: none; }
        .breadcrumb span { color: #999; margin: 0 5px; }
        .badge { background: #e5e7eb; padding: 2px 6px; border-radius: 10px; font-size: 12px; }
        .lang-field { border-left: 3px solid var(--primary); padding-left: 10px; margin-bottom: 10px; }
        .lang-label { font-size: 11px; text-transform: uppercase; color: #999; font-weight: bold; }
        .structure-row { display: flex; gap: 10px; align-items: center; margin-bottom: 10px; padding: 10px; background: #f9fafb; border: 1px solid #eee; }
    </style>
</head>
<body>

<div class="container">
    
    <?php if ($action === 'dashboard'): ?>
        <div class="nav-bar">
            <h1>Content Types</h1>
        </div>
        
        <div class="grid">
            <?php foreach($groups as $name => $count): ?>
                <div class="card">
                    <div style="display:flex; justify-content:space-between;">
                        <h3><?= htmlspecialchars($name) ?></h3>
                        <span class="badge"><?= $count ?> items</span>
                    </div>
                    <div style="margin-top: 15px; display: flex; gap: 5px;">
                        <a href="?action=list&group=<?= $name ?>" class="btn">Manage Data</a>
                        <a href="?action=structure&group=<?= $name ?>" class="btn btn-outline">Edit Fields</a>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <div class="card" style="border: 2px dashed #ddd; display: flex; align-items: center; justify-content: center;">
                <form method="POST" style="width: 100%;">
                    <input type="hidden" name="post_action" value="create_group">
                    <h3>New Content Group</h3>
                    <div style="display: flex; gap: 5px;">
                        <input type="text" name="group_name" placeholder="e.g. blog_posts" required>
                        <button type="submit" class="btn">+ Create</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($action === 'list' && $currentGroup): ?>
        <div class="nav-bar">
            <div class="breadcrumb">
                <a href="?action=dashboard">Dashboard</a> <span>/</span> <?= htmlspecialchars($currentGroup) ?>
            </div>
            <form method="POST">
                <input type="hidden" name="post_action" value="add_entity">
                <input type="hidden" name="group" value="<?= $currentGroup ?>">
                <button class="btn">+ Add New Item</button>
            </form>
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th width="50">ID</th>
                        <th>Preview (First Field)</th>
                        <th width="150">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $entities = array_filter($data, fn($i) => $i['name'] === $currentGroup);
                    foreach($entities as $entity): 
                        $firstField = $entity['data'][0]['value'] ?? '';
                        $preview = is_array($firstField) ? (reset($firstField)) : $firstField;
                    ?>
                    <tr>
                        <td>#<?= $entity['id'] ?></td>
                        <td><?= htmlspecialchars(substr(strip_tags($preview), 0, 50)) ?>...</td>
                        <td>
                            <a href="?action=edit&group=<?= $currentGroup ?>&id=<?= $entity['id'] ?>" class="btn btn-outline" style="padding: 4px 8px;">Edit</a>
                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this item?');">
                                <input type="hidden" name="post_action" value="delete_entity">
                                <input type="hidden" name="group" value="<?= $currentGroup ?>">
                                <input type="hidden" name="id" value="<?= $entity['id'] ?>">
                                <button class="btn btn-danger" style="padding: 4px 8px;">×</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div style="margin-top: 20px; text-align: right;">
            <form method="POST" onsubmit="return confirm('WARNING: This will delete the entire group and all its data. Cannot be undone.');">
                <input type="hidden" name="post_action" value="delete_group">
                <input type="hidden" name="group" value="<?= $currentGroup ?>">
                <button class="btn btn-danger">Delete Entire Group</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($action === 'edit' && $currentGroup && isset($currentId)): 
        $entity = null;
        foreach($data as $item) {
            if ($item['name'] === $currentGroup && $item['id'] == $currentId) {
                $entity = $item; break;
            }
        }
    ?>
        <div class="nav-bar">
            <div class="breadcrumb">
                <a href="?action=dashboard">Dashboard</a> <span>/</span> 
                <a href="?action=list&group=<?= $currentGroup ?>"><?= htmlspecialchars($currentGroup) ?></a> <span>/</span>
                Edit #<?= $currentId ?>
            </div>
        </div>

        <form method="POST">
            <input type="hidden" name="post_action" value="save_entity">
            <input type="hidden" name="group" value="<?= $currentGroup ?>">
            <input type="hidden" name="id" value="<?= $currentId ?>">

            <div class="card">
                <?php foreach($entity['data'] as $field): ?>
                    <div class="form-group">
                        <label style="font-weight: bold;"><?= htmlspecialchars($field['name']) ?></label>
                        
                        <?php if (is_array($field['value'])): ?>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 5px;">
                                <?php foreach($languages as $lang): ?>
                                    <div class="lang-field">
                                        <div class="lang-label"><?= $lang ?></div>
                                        <textarea name="<?= $field['name'] ?>[<?= $lang ?>]" rows="3"><?= htmlspecialchars($field['value'][$lang] ?? '') ?></textarea>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <textarea name="<?= $field['name'] ?>" rows="2"><?= htmlspecialchars($field['value']) ?></textarea>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn">Save Changes</button>
                    <a href="?action=list&group=<?= $currentGroup ?>" class="btn btn-outline">Cancel</a>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($action === 'structure' && $currentGroup): 
        $sampleSchema = getGroupSchema($data, $currentGroup);
    ?>
        <div class="nav-bar">
            <div class="breadcrumb">
                <a href="?action=dashboard">Dashboard</a> <span>/</span> 
                <a href="?action=list&group=<?= $currentGroup ?>"><?= htmlspecialchars($currentGroup) ?></a> <span>/</span>
                Edit Structure
            </div>
        </div>

        <div class="card">
            <h3>Manage Fields for "<?= htmlspecialchars($currentGroup) ?>"</h3>
            <p style="font-size: 0.9em; color: #666; margin-bottom: 20px;">
                Adding fields here adds them to ALL items in this group. Removing them deletes data for that field permanently.
            </p>

            <form method="POST" id="structureForm">
                <input type="hidden" name="post_action" value="update_structure">
                <input type="hidden" name="group_name" value="<?= $currentGroup ?>">
                
                <div id="fields-container">
                    <?php foreach($sampleSchema as $idx => $field): ?>
                        <div class="structure-row">
                            <span style="color:#999;">Field Name:</span>
                            <input type="text" name="fields[<?= $idx ?>][name]" value="<?= htmlspecialchars($field['name']) ?>" required pattern="[a-z0-9_]+">
                            <label style="display:flex; align-items:center; gap:5px; font-size:0.9em;">
                                <input type="checkbox" name="fields[<?= $idx ?>][is_multi]" <?= is_array($field['value']) ? 'checked' : '' ?>> 
                                Multilingual
                            </label>
                            <button type="button" class="btn btn-danger" onclick="this.parentElement.remove()" style="padding: 4px 10px;">&times;</button>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top: 20px; display: flex; gap: 10px;">
                    <button type="button" class="btn btn-outline" onclick="addField()">+ Add Field</button>
                    <button type="submit" class="btn">Save Structure</button>
                </div>
            </form>
        </div>

        <script>
            let fieldCount = 1000;
            function addField() {
                const container = document.getElementById('fields-container');
                const div = document.createElement('div');
                div.className = 'structure-row';
                div.innerHTML = `
                    <span style="color:#999;">Field Name:</span>
                    <input type="text" name="fields[${fieldCount}][name]" placeholder="field_name" required pattern="[a-z0-9_]+">
                    <label style="display:flex; align-items:center; gap:5px; font-size:0.9em;">
                        <input type="checkbox" name="fields[${fieldCount}][is_multi]"> 
                        Multilingual
                    </label>
                    <button type="button" class="btn btn-danger" onclick="this.parentElement.remove()" style="padding: 4px 10px;">&times;</button>
                `;
                container.appendChild(div);
                fieldCount++;
            }
        </script>
    <?php endif; ?>

</div>

</body>
</html>