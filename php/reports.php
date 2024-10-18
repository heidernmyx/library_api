<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

include '../php/connection/connection.php'; 

class Reports {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * **Fetch Popular Books**
     * Retrieves books based on the number of times they have been borrowed.
     */
    public function fetchPopularBooks() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    COUNT(bb.BorrowID) AS BorrowCount
                FROM 
                    borrowed_books bb
                JOIN books b ON bb.BookID = b.BookID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                GROUP BY 
                    b.BookID
                ORDER BY 
                    BorrowCount DESC
                LIMIT 10
            ");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'popular_books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch popular books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Overdue Books**
     * Retrieves books that are overdue.
     */
    public function fetchOverdueBooks() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    bb.BorrowID,
                    b.Title,
                    u.Name AS UserName,
                    bb.BorrowDate,
                    bb.DueDate,
                    DATEDIFF(CURDATE(), bb.DueDate) AS DaysOverdue
                FROM 
                    borrowed_books bb
                JOIN books b ON bb.BookID = b.BookID
                JOIN users u ON bb.UserID = u.UserID
                WHERE 
                    bb.DueDate < CURDATE() AND bb.StatusID = 4
                ORDER BY 
                    bb.DueDate ASC
            ");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'overdue_books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch overdue books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Most Reserved Books**
     * Retrieves books based on the number of reservations.
     */
    public function fetchMostReservedBooks() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    b.BookID,
                    b.Title,
                    a.AuthorName,
                    COUNT(r.ReservationID) AS ReservationCount
                FROM 
                    reservations r
                JOIN books b ON r.BookID = b.BookID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                GROUP BY 
                    b.BookID
                ORDER BY 
                    ReservationCount DESC
                LIMIT 10
            ");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'most_reserved_books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch most reserved books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Currently Borrowed Books**
     * Retrieves books that are currently borrowed.
     */
    public function fetchCurrentlyBorrowedBooks() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    bb.BorrowID,
                    b.Title,
                    a.AuthorName,
                    u.Name AS UserName,
                    bb.BorrowDate,
                    bb.DueDate
                FROM 
                    borrowed_books bb
                JOIN books b ON bb.BookID = b.BookID
                JOIN users u ON bb.UserID = u.UserID
                LEFT JOIN authors a ON b.AuthorID = a.AuthorID
                WHERE 
                    bb.StatusID = 4
                ORDER BY 
                    bb.DueDate ASC
            ");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'currently_borrowed_books' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch currently borrowed books.',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * **Fetch Late Returners**
     * Retrieves users who have late returns, useful for tracking frequent late returners.
     */
    public function fetchLateReturners() {
        try {
            $stmt = $this->pdo->prepare("
                SELECT 
                    u.UserID,
                    u.Name AS UserName,
                    COUNT(bb.BorrowID) AS LateReturns
                FROM 
                    borrowed_books bb
                JOIN users u ON bb.UserID = u.UserID
                WHERE 
                    bb.DueDate < CURDATE() AND bb.StatusID = 4
                GROUP BY 
                    u.UserID
                ORDER BY 
                    LateReturns DESC
                LIMIT 10
            ");
            $stmt->execute();
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode(['success' => true, 'late_returners' => $data]);
        } catch (\PDOException $e) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Failed to fetch late returners.',
                'error' => $e->getMessage()
            ]);
        }
    }
}

// **Handle Incoming Requests**
if ($_SERVER['REQUEST_METHOD'] == 'GET') {
    $operation = isset($_GET['operation']) ? $_GET['operation'] : '';
}
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $operation = isset($_POST['operation']) ? $_POST['operation'] : '';
}

$reports = new Reports($pdo);

switch ($operation) {
    case 'fetchPopularBooks':
        $reports->fetchPopularBooks();
        break;
    case 'fetchOverdueBooks':
        $reports->fetchOverdueBooks();
        break;
    case 'fetchMostReservedBooks':
        $reports->fetchMostReservedBooks();
        break;
    case 'fetchCurrentlyBorrowedBooks':
        $reports->fetchCurrentlyBorrowedBooks();
        break;
    case 'fetchLateReturners':
        $reports->fetchLateReturners();
        break;
    default:
        http_response_code(400); 
        echo json_encode(['success' => false, 'message' => 'Invalid operation.']);
        break;
}

?>
