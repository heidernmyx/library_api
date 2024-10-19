<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

include '../php/connection/connection.php'; // Assuming connection.php establishes a valid PDO connection.

class Genre {
  private $pdo;

  public function __construct($pdo) {
    $this->pdo = $pdo;
  }

  // Fetch all genres
  function fetchGenres() {
    $stmt = $this->pdo->prepare("SELECT * FROM genres");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    unset($this->pdo);
    unset($stmt);

    echo json_encode($result);
  }

  // Add a new genre
  function addGenre($json) {
    // Check if the genre exists
    $stmt = $this->pdo->prepare("SELECT * FROM genres WHERE GenreName = :genreName");
    $stmt->bindParam(':genreName', $json['genreName'], PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      http_response_code(409); // Conflict
      echo json_encode(['success' => false, 'message' => 'Genre already exists.']);
      exit;
    }

    // Insert the new genre
    $sql = "INSERT INTO genres (GenreName) VALUES (:genreName)";
    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':genreName', $json['genreName'], PDO::PARAM_STR);
    $stmt->execute();
    $result = $stmt->rowCount() > 0 ? 1 : 0;

    unset($this->pdo);
    unset($stmt);

    echo json_encode(['success' => $result == 1, 'message' => $result == 1 ? 'Genre added successfully' : 'Failed to add genre']);
  }

  // Update an existing genre
  function updateGenre($json) {
    // Check if the genre exists
    $stmt = $this->pdo->prepare("SELECT * FROM genres WHERE GenreID = :genreID");
    $stmt->bindParam(':genreID', $json['genreID'], PDO::PARAM_INT);
    $stmt->execute();
    $existingGenre = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingGenre) {
      http_response_code(404); // Not Found
      echo json_encode(['success' => false, 'message' => 'Genre not found.']);
      exit;
    }

    // Update the genre
    $sql = "UPDATE genres SET GenreName = :genreName WHERE GenreID = :genreID";
    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':genreName', $json['genreName'], PDO::PARAM_STR);
    $stmt->bindParam(':genreID', $json['genreID'], PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->rowCount() > 0 ? 1 : 0;

    unset($this->pdo);
    unset($stmt);

    echo json_encode(['success' => $result == 1, 'message' => $result == 1 ? 'Genre updated successfully' : 'Failed to update genre']);
  }

  // Archive a genre (soft delete)
  function archiveGenre($json) {
    // Check if the genre exists
    $stmt = $this->pdo->prepare("SELECT * FROM genres WHERE GenreID = :genreID");
    $stmt->bindParam(':genreID', $json['genreID'], PDO::PARAM_INT);
    $stmt->execute();
    $existingGenre = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existingGenre) {
      http_response_code(404); // Not Found
      echo json_encode(['success' => false, 'message' => 'Genre not found.']);
      exit;
    }

    // Soft delete by marking the genre as archived (assuming there's a column named 'IsArchived')
    $sql = "UPDATE genres SET IsArchived = 1 WHERE GenreID = :genreID";
    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':genreID', $json['genreID'], PDO::PARAM_INT);
    $stmt->execute();
    $result = $stmt->rowCount() > 0 ? 1 : 0;

    unset($this->pdo);
    unset($stmt);

    echo json_encode(['success' => $result == 1, 'message' => $result == 1 ? 'Genre archived successfully' : 'Failed to archive genre']);
  }

  // Fetch archived genres
  function fetchArchivedGenres() {
    $stmt = $this->pdo->prepare("SELECT * FROM genres WHERE IsArchived = 1");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    unset($this->pdo);
    unset($stmt);

    echo json_encode($result);
  }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $operation = $_POST['operation'];
  $json = isset($_POST['json']) ? json_decode($_POST['json'], true) : null;
} elseif ($_SERVER['REQUEST_METHOD'] == 'GET') {
  $operation = $_GET['operation'];
  $json = isset($_GET['json']) ? json_decode($_GET['json'], true) : null;
}

$genre = new Genre($pdo);

switch ($operation) {
  case 'fetchGenres':
    $genre->fetchGenres();
    break;
  case 'addGenre':
    $genre->addGenre($json);
    break;
  case 'updateGenre':
    $genre->updateGenre($json);
    break;
  case 'archiveGenre':
    $genre->archiveGenre($json);
    break;
  case 'fetchArchivedGenres':
    $genre->fetchArchivedGenres();
    break;
  default:
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'message' => 'Invalid operation']);
    break;
}

?>
