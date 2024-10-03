<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');


include '../php/connection/connection.php';
class Genre {
  private $pdo;

  public function __construct($pdo) {
    $this->pdo = $pdo;
  }

  function fetchGenres() {
    $stmt = $this->pdo->prepare("SELECT * FROM genres");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);


    unset($this->pdo);
    unset($stmt);

    echo json_encode($result);

  }

  function addGenre($json) {

    // check if genre exists
    $stmt = $this->pdo->prepare("SELECT * FROM genres WHERE GenreName = :genreName");
    $stmt->bindParam(':genreName', $json['genreName'], PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      http_response_code(409); // Conflict
      echo json_encode(['success' => false, 'message' => 'Genre already exists.']);
      exit;
    }

    $sql = "INSERT INTO genres (GenreName) VALUES (:genreName)";

    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':genreName', $json['genreName'], PDO::PARAM_STR);
    $stmt->execute();
    $result = $stmt->rowCount() > 0 ? 1 : 0;
    // if 1 ang i return success ang pag insert

    unset($this->pdo);
    unset($stmt);

    echo json_encode($result);
  }

  function updateGenre ($json) {

    // check if genre exists
    $stmt = $this->pdo->prepare("SELECT * FROM genres WHERE GenreName = :genreName");
    $stmt->bindParam(':genreName', $json['genreName'], PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      http_response_code(409); // Conflict
      echo json_encode(['success' => false, 'message' => 'Genre already exists.']);
      exit;
    }

    // $sql = "UPDATE genres SET GenreName = :genreName WHERE GenreID = :genreID";
    // $stmt = $this->pdo->prepare($sql);
    // $stmt->bindParam(':newgenreName', $json['genreName'], PDO::PARAM_STR);
  }

}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
  $operation = $_POST['operation'];
  $json = isset($_POST['json']) ? json_decode($_POST['json'], true) : null;
}
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
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
}



// formdata = new FormData();
// formdata.append('operation', 'addGenre');
// formdata.append('json', JSON.stringify({genreName: 'Fantasy'}));

// add genre post req sample

// key = operation, value = addGenre
// key = json,      value = {"genreName": "Fantasy"}