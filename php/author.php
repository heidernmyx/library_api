<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: localhost:3000');


include '../php/connection/connection.php';

class Author {
  private $pdo;

  public function __construct($pdo) {
    $this->pdo = $pdo;
  }

  function fetchAuthors() {
    $stmt = $this->pdo->prepare("SELECT * FROM authors");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    unset($this->pdo);
    unset($stmt);

    echo json_encode($result);
    
  }

  function addAuthor($json) {

    $sql = 'SELECT * FROM authors WHERE AuthorName = :authorName';
    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':authorName', $json['authorName'], PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      http_response_code(409); // Conflict
      echo json_encode(['success' => false, 'message' => 'Author already exists.']);
      exit;
    }

  }

  function updateAuthor($json) {

    $sql = 'SELECT * FROM authors WHERE AuthorName = :authorName';
    $stmt = $this->pdo->prepare($sql);
    $stmt->bindParam(':authorName', $json['authorName'], PDO::PARAM_STR);
    $stmt->execute();

    if ($stmt->fetch(PDO::FETCH_ASSOC)) {
      http_response_code(409); // Conflict
      echo json_encode(['success' => false, 'message' => 'Author already exists.']);
      exit;
    }

  }


}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $operation = $_POST['operation'];
  $json = isset($_POST['json']) ? json_decode($_POST['json'], true) : null; 
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $operation = $_GET['operation'];
  $json = isset($_GET['json']) ? json_decode($_GET['json'], true) : null; 
}

$author->fetchAuthors();
switch($operation) {
  case 'fetchAuthors':
    $author->fetchAuthors();
    break;
  case 'addAuthor';
    $author->addAuthor($json);
    break;
  case 'updateAuthor';
    $author->updateAuthor($json);
    break;
  default:
    echo json_encode(['error' => 'No operation provided.']);
    break;

}