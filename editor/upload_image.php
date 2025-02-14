<?php
// Corrected path: go up one level from editor directory
$targetDir = "../src/";
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (!empty($_FILES["image"]["name"])) {
    // Sanitize filename
    $filename = isset($_POST["filename"]) 
        ? basename($_POST["filename"]) 
        : basename($_FILES["image"]["name"]);
    $targetFile = $targetDir . $filename;

    // Additional security checks recommended
    $imageFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $allowedTypes = ['jpg', 'jpeg', 'png', 'gif'];
    
    if (!in_array($imageFileType, $allowedTypes)) {
        die(json_encode(["success" => false, "error" => "Invalid file type."]));
    }

    if (move_uploaded_file($_FILES["image"]["tmp_name"], $targetFile)) {
        echo json_encode(["success" => true, "filepath" => $targetFile]);
    } else {
        echo json_encode(["success" => false, "error" => "Upload failed."]);
    }
} else {
    echo json_encode(["success" => false, "error" => "No file received."]);
}
?>