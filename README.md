Book and E-Learning Platform
This project is a comprehensive full-stack web application designed to manage and deliver educational content through a tiered membership model. It features a robust backend for handling user authentication, session management, and automated payment processing via the Safaricom Daraja API.

System Overview The platform operates on a three-tier access hierarchy:

Basic: Instant access to introductory resources upon registration.

Premium: Access to intermediate technical and professional development content.

VIP: Full access to all library resources, including specialized financial and real estate modules.

Shutterstock Explore Core Functionalities Tiered Membership Engine: Restricts content visibility based on the user's current subscription level.

Automated Payment Processing: Integration with M-Pesa STK Push for real-time account upgrades and activation.

Secure Authentication: Implementation of PHP sessions, password hashing, and prepared statements for database security.

Asynchronous Callbacks: A dedicated listener for Safaricom's API responses to ensure payment confirmation without user intervention.

Asset Integrity Management: Includes diagnostic tools to verify the availability of digital PDF resources within the server filesystem.

Technical Workflow

Registration and Authentication Users provide their details through a dynamic registration interface. Basic accounts are activated immediately, while Premium and VIP accounts remain in a Pending state until payment is verified.

M-Pesa STK Push Sequence When an upgrade or paid registration is initiated:

The system generates an OAuth2 access token.

An STK Push request is dispatched to the user's mobile device.

The system waits for a response at the defined CallBackURL.

File Structure Backend Components (/backend/api/) config.php: Database connection configuration using mysqli.

login.php / register.php: User authentication and account creation logic.

stkpush.php: Coordination of the M-Pesa STK Push request.

callback.php: The API listener that processes JSON responses from Safaricom and updates the database.

books.php: The content firewall that validates user credentials before serving PDF files.

logout.php: Session termination and security cleanup.

Frontend Components (/frontend/) index.php: The primary dashboard and membership management interface.

login_view.php: Dedicated user login interface.

check_files.php: A utility for verifying the existence of library assets in the uploads/ directory.

Content Assets (/uploads/) The library includes various educational modules such as:

healthy_living.pdf

african_recipes.pdf

python_basics.pdf

financial_freedom.pdf

real_estate_101.pdf

Database Configuration The application requires a MySQL database named elearning_db. Use the following schema to initialize the environment:

SQL CREATE TABLE users ( id INT AUTO_INCREMENT PRIMARY KEY, fullname VARCHAR(100) NOT NULL, email VARCHAR(100) UNIQUE NOT NULL, phone VARCHAR(20) NOT NULL, password VARCHAR(255) NOT NULL, membership ENUM('Basic', 'Premium', 'VIP') NOT NULL, payment_status ENUM('Pending', 'Paid') DEFAULT 'Pending', mpesa_receipt VARCHAR(50) DEFAULT NULL );

CREATE TABLE books ( id INT AUTO_INCREMENT PRIMARY KEY, title VARCHAR(255) NOT NULL, description TEXT, file_path VARCHAR(255) NOT NULL, membership_required ENUM('Basic', 'Premium', 'VIP') NOT NULL ); Installation and Setup Directory Placement: Clone or move the project files to your local server directory (e.g., htdocs for XAMPP).

Database Import: Execute the SQL schema provided above in your MySQL environment.

API Credentials:

Update backend/api/config.php with your local database password.

Update backend/api/stkpush.php with your M-Pesa Consumer Key and Consumer Secret.

Callback Configuration: For local testing, use a tunneling service like Ngrok to expose your local server to the internet. Update the $CallBackURL in stkpush.php to match your public URL.

Asset Check: Run frontend/check_files.php in your browser to ensure all PDF books are correctly located in the uploads/ folder.

Security Implementation Password Encryption: All passwords are processed via password_hash() using the BCRYPT algorithm.

SQL Injection Protection: All database queries utilize Prepared Statements to mitigate injection risks.

Session Guard: Dashboard access and book downloads are protected by server-side session validation.
