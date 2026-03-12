Book and E-Learning Platform
This project is a comprehensive full-stack web application designed to manage and deliver educational content through a tiered membership model. It features a robust backend for handling user authentication, session management, and automated payment processing via the Safaricom Daraja API.
# Books E-Learning Platform

A PHP and MySQL learning platform with tiered memberships, protected book access, quiz features, and M-Pesa (Daraja) STK Push payments.

## Features

- Membership tiers: Basic, Premium, and VIP.
- Login and registration with secure password hashing.
- M-Pesa STK Push integration for paid plans.
- Callback-based payment confirmation.
- Live waiting screen after STK push with auto status polling.
- Dashboard sync button to refresh payment state.
- Protected book access based on membership and payment status.
- Quiz section with multiple questions and score feedback.
- File integrity checker for uploads.

## Project Structure

### Backend

- `backend/api/config.php`: MySQL database connection.
- `backend/api/register.php`: User registration.
- `backend/api/login.php`: User authentication.
- `backend/api/logout.php`: Session logout.
- `backend/api/stkpush.php`: STK push initiation and waiting UI.
- `backend/api/callback.php`: Safaricom callback handler.
- `backend/api/payment_status.php`: JSON endpoint for payment polling.
- `backend/api/books.php`: Protected book download/access route.

### Frontend

- `frontend/index.php`: Main app UI (home, books, membership, quiz, dashboard).
- `frontend/login_view.php`: Login page.
- `frontend/check_files.php`: Uploads diagnostic utility.

### Assets

- `uploads/`: PDF files used by the platform.

## Requirements

- XAMPP or similar (Apache + MySQL + PHP).
- PHP 8.x recommended.
- MySQL/MariaDB.
- Ngrok (for local callback testing with M-Pesa sandbox).

## Database Setup

Create a database named `elearning_db`, then run:

```sql
CREATE TABLE users (
		id INT AUTO_INCREMENT PRIMARY KEY,
		fullname VARCHAR(100) NOT NULL,
		email VARCHAR(100) UNIQUE NOT NULL,
		phone VARCHAR(20) NOT NULL,
		password VARCHAR(255) NOT NULL,
		membership ENUM('Basic','Premium','VIP') NOT NULL,
		payment_status ENUM('Pending','Paid') DEFAULT 'Pending',
		mpesa_receipt VARCHAR(50) DEFAULT NULL
);

CREATE TABLE books (
		id INT AUTO_INCREMENT PRIMARY KEY,
		title VARCHAR(255) NOT NULL,
		description TEXT,
		file_path VARCHAR(255) NOT NULL,
		membership_required ENUM('Basic','Premium','VIP') NOT NULL
);
```

## Configuration

1. Place the project in your web root, for example `C:\xampp\htdocs\Books-Elearning-platform`.
2. Update database credentials in `backend/api/config.php`.
3. Set your Daraja sandbox credentials in `backend/api/stkpush.php`.
4. Ensure upload files exist in `uploads/`.

## Running the Project

1. Start Apache and MySQL from XAMPP.
2. Open:
	 `http://localhost/Books-Elearning-platform/frontend/index.php`
3. Register or log in.

## M-Pesa Flow

1. User selects Premium or VIP.
2. Membership is set to `Pending` until payment is confirmed.
3. STK push is sent from `stkpush.php`.
4. User sees a spinner/waiting page while the app polls `payment_status.php`.
5. Safaricom callback hits `callback.php` and updates user to `Paid`.
6. UI auto-redirects to dashboard after confirmation.

## Callback URL Behavior

- If `MPESA_CALLBACK_URL` is set, that URL is used.
- If not set, system tries to infer from current host.
- On localhost, it attempts to read ngrok tunnel URL from:
	`http://127.0.0.1:4040/api/tunnels`
- If no ngrok tunnel is available, payment request stops with a clear error.

## Notes for Local Testing

- Keep ngrok running during STK tests.
- Open the app via localhost or ngrok, but ensure callback URL is publicly reachable.
- Use dashboard Sync button if callback is delayed.

## Security Practices Used

- Password hashing using `password_hash()`.
- SQL prepared statements to reduce injection risk.
- Session-based access control for protected routes.

## Troubleshooting

- Callback unreachable:
	Confirm ngrok is active or set `MPESA_CALLBACK_URL`.
- Payment remains pending:
	Check callback logs and use dashboard sync.
- Books not available:
	Run `frontend/check_files.php` and verify files in `uploads/`.

## License

Use and modify for learning or internal project use.

