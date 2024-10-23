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
     * **Get Admin Attention List**
     * Retrieves a comprehensive list of items that require administrative attention,
     * including overdue books, upcoming due dates, expiring reservations, and users with late fees.
     *
     * @return array
     */
   public function getAdminAttentionList() {
    try {
        // 1. Fetch Overdue Books
        $stmtOverdue = $this->pdo->prepare("
            SELECT 
                bb.BorrowID,
                u.UserID,
                u.Fname,
                b.Title,
                bb.DueDate,
                DATEDIFF(CURDATE(), bb.DueDate) AS DaysOverdue
            FROM 
                borrowed_books bb
            JOIN 
                users u ON bb.UserID = u.UserID
            JOIN 
                books b ON bb.BookID = b.BookID
            WHERE 
                bb.DueDate < CURDATE() 
                AND bb.StatusID = 4
        ");
        $stmtOverdue->execute();
        $attentionList['overdueBooks'] = $stmtOverdue->fetchAll(PDO::FETCH_ASSOC);

        // 2. Fetch Books Due Soon (e.g., due in next 3 days)
        $stmtDueSoon = $this->pdo->prepare("
            SELECT 
                bb.BorrowID,
                u.UserID,
                u.Fname,
                b.Title,
                bb.DueDate,
                DATEDIFF(bb.DueDate, CURDATE()) AS DaysUntilDue
            FROM 
                borrowed_books bb
            JOIN 
                users u ON bb.UserID = u.UserID
            JOIN 
                books b ON bb.BookID = b.BookID
            WHERE 
                bb.DueDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 DAY)
                AND bb.StatusID = 4
        ");
        $stmtDueSoon->execute();
        $attentionList['dueSoonBooks'] = $stmtDueSoon->fetchAll(PDO::FETCH_ASSOC);

        // 3. Fetch Reservations About to Expire (e.g., in next 2 days)
        $stmtExpiringReservations = $this->pdo->prepare("
            SELECT 
                r.ReservationID,
                u.UserID,
                u.Fname,
                b.Title,
                r.ExpirationDate,
                DATEDIFF(r.ExpirationDate, CURDATE()) AS DaysUntilExpiry
            FROM 
                reservations r
            JOIN 
                users u ON r.UserID = u.UserID
            JOIN 
                books b ON r.BookID = b.BookID
            WHERE 
                r.ExpirationDate BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 2 DAY)
                AND r.StatusID = 5
        ");
        $stmtExpiringReservations->execute();
        $attentionList['expiringReservations'] = $stmtExpiringReservations->fetchAll(PDO::FETCH_ASSOC);

    
        echo json_encode($attentionList);

    } catch (\PDOException $e) {
        
        http_response_code(500); 
        echo json_encode([
            'success' => false,
            'message' => 'Failed to fetch admin attention list.',
            'error' => $e->getMessage()
        ]);
    }
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
                    u.Fname AS Fname,
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
                    u.Fname AS Fname,
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
                    u.Fname AS Fname,
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
    case 'getAdminAttentionList':
        $reports->getAdminAttentionList();
        // echo json_encode(['success' => true, 'attention_list' => $attentionList]);
        break;
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
