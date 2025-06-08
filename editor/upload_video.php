<?php
file_put_contents("debug.log", print_r($_FILES, true));

$targetDir = "../src/";
if (!is_dir($targetDir)) {
    mkdir($targetDir, 0777, true);
}

if (!empty($_FILES["video"]["name"])) {
    $filename = isset($_POST["filename"]) 
        ? basename($_POST["filename"]) 
        : basename($_FILES["video"]["name"]);
    $targetFile = $targetDir . $filename;

    $videoFileType = strtolower(pathinfo($targetFile, PATHINFO_EXTENSION));
    $allowedTypes = ['mp4', 'webm', 'ogg'];

    if (!in_array($videoFileType, $allowedTypes)) {
        die(json_encode(["success" => false, "error" => "Invalid video format."]));
    }

    if (move_uploaded_file($_FILES["video"]["tmp_name"], $targetFile)) {
        echo json_encode(["success" => true, "filepath" => $targetFile]);
    } else {
        echo json_encode(["success" => false, "error" => "Upload failed."]);
    }
} else {
    echo json_encode(["success" => false, "error" => "No video received."]);
}
?>
