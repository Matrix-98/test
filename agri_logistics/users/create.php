<?php
require_once '../config/db.php';
require_once '../utils/id_generator.php';

if (!isset($_SESSION["loggedin"]) || $_SESSION["loggedin"] !== true) {
    header("location: " . BASE_URL . "index.php");
    exit;
}

if ($_SESSION["role"] != 'admin') {
    $_SESSION['error_message'] = "You do not have permission to create users.";
    header("location: " . BASE_URL . "dashboard.php");
    exit;
}

$page_title = "Add User";
$current_page = "users";

$username = $password = $confirm_password = $role = $email = $phone = $customer_type = "";
$username_err = $password_err = $confirm_password_err = $role_err = $email_err = $customer_type_err = $phone_err = "";
$assigned_locations = []; // For storing assigned locations
$all_locations_options = []; // For the multi-select dropdown

// Fetch all locations to populate the multi-select dropdown
$sql_all_locations = "SELECT location_id, name, type FROM locations ORDER BY name ASC";
if ($result_all_locations = mysqli_query($conn, $sql_all_locations)) {
    while ($row = mysqli_fetch_assoc($result_all_locations)) {
        $all_locations_options[] = $row;
    }
    mysqli_free_result($result_all_locations);
}


if ($_SERVER["REQUEST_METHOD"] == "POST") {

    // Validate Username
    if (empty(trim($_POST["username"]))) {
        $username_err = "Please enter a username.";
    } else {
        $sql_check_username = "SELECT user_id FROM users WHERE username = ? UNION SELECT request_id FROM registration_requests WHERE username = ?";
        if ($stmt_check = mysqli_prepare($conn, $sql_check_username)) {
            mysqli_stmt_bind_param($stmt_check, "ss", $param_username, $param_username);
            $param_username = trim($_POST["username"]);
            if (mysqli_stmt_execute($stmt_check)) {
                mysqli_stmt_store_result($stmt_check);
                if (mysqli_stmt_num_rows($stmt_check) > 0) {
                    $username_err = "This username is already taken or in review.";
                } else {
                    $username = trim($_POST["username"]);
                }
            } else {
                error_log("Error checking duplicate username during registration: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_check);
        }
    }

    // Validate Password Policy
    if (empty(trim($_POST["password"]))) {
        $password_err = "Please enter a password.";
    } elseif (strlen(trim($_POST["password"])) < 8) {
        $password_err = "Password must be at least 8 characters long.";
    } elseif (!preg_match('/[A-Z]/', $_POST["password"])) {
        $password_err = "Password must contain at least one uppercase letter.";
    } elseif (!preg_match('/[a-z]/', $_POST["password"])) {
        $password_err = "Password must contain at least one lowercase letter.";
    } elseif (!preg_match('/[0-9]/', $_POST["password"])) {
        $password_err = "Password must contain at least one digit.";
    } elseif (!preg_match('/[^A-Za-z0-9]/', $_POST["password"])) {
        $password_err = "Password must contain at least one special character (e.g., !@#$%^&*).";
    } else {
        $password = trim($_POST["password"]);
    }

    // Validate Confirm Password
    if (empty(trim($_POST["confirm_password"]))) {
        $confirm_password_err = "Please confirm password.";
    } else {
        $confirm_password = trim($_POST["confirm_password"]);
        if (empty($password_err) && ($password != $confirm_password)) {
            $confirm_password_err = "Password did not match.";
        }
    }

    // Validate role
    $allowed_roles = ['admin', 'farm_manager', 'warehouse_manager', 'logistics_manager', 'driver', 'customer'];
    if (empty(trim($_POST["role"])) || !in_array(trim($_POST["role"]), $allowed_roles)) {
        $role_err = "Please select a valid role.";
    } else {
        $role = trim($_POST["role"]);
    }

    // Validate customer type ONLY if role is 'customer'
    if ($role == 'customer') {
        $allowed_customer_types = ['direct', 'retailer'];
        if (empty(trim($_POST["customer_type"])) || !in_array(trim($_POST["customer_type"]), $allowed_customer_types)) {
            $customer_type_err = "Please select a valid customer type for customer role.";
        } else {
            $customer_type = trim($_POST["customer_type"]);
        }
    } else {
        $customer_type = 'direct'; 
    }

    // Validate Email and check for uniqueness
    if (empty(trim($_POST["email"]))) {
        $email_err = "Please enter an email address.";
    } elseif (!filter_var(trim($_POST["email"]), FILTER_VALIDATE_EMAIL)) {
        $email_err = "Please enter a valid email address.";
    } else {
        $sql_check_email = "SELECT user_id FROM users WHERE email = ? UNION SELECT request_id FROM registration_requests WHERE email = ?";
        if ($stmt_email_check = mysqli_prepare($conn, $sql_check_email)) {
            mysqli_stmt_bind_param($stmt_email_check, "ss", $param_email, $param_email);
            $param_email = trim($_POST["email"]);
            if (mysqli_stmt_execute($stmt_email_check)) {
                mysqli_stmt_store_result($stmt_email_check);
                if (mysqli_stmt_num_rows($stmt_email_check) > 0) {
                    $email_err = "This email is already registered or in review.";
                } else {
                    $email = trim($_POST["email"]);
                }
            } else {
                error_log("Error checking duplicate email during registration: " . mysqli_error($conn));
            }
            mysqli_stmt_close($stmt_email_check);
        }
    }

    // Validate Phone - now mandatory
    if (empty(trim($_POST["phone"]))) {
        $phone_err = "Please enter your phone number.";
    } else {
        $phone = trim($_POST["phone"]);
    }

    // Capture assigned location for warehouse managers
    $assigned_locations = []; // Reset this every time
    if ($role == 'warehouse_manager' && isset($_POST['assigned_locations']) && !empty($_POST['assigned_locations'])) {
        $loc_id = (int)$_POST['assigned_locations'];
        if (is_numeric($loc_id) && $loc_id > 0) {
            $assigned_locations[] = $loc_id;
        }
        // If warehouse manager selected but no location, add an error
        if (empty($assigned_locations)) {
            $email_err = "Warehouse Manager must be assigned to a warehouse."; // Re-using email_err or create new
        }
    }


    // Check for any validation errors before inserting into database
    // Ensure all errors are caught, including location assignment error
    if (empty($username_err) && empty($password_err) && empty($confirm_password_err) && empty($role_err) && empty($email_err) && empty($phone_err) && empty($customer_type_err)) {
        mysqli_begin_transaction($conn);
        try {
            $logged_in_user_id = $_SESSION['user_id'];
            $user_code = generateUserId();
            $sql = "INSERT INTO users (user_code, username, password_hash, role, customer_type, email, phone, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

            if ($stmt = mysqli_prepare($conn, $sql)) {
                mysqli_stmt_bind_param($stmt, "sssssssi", $param_user_code, $param_username, $param_password_hash, $param_role, $param_customer_type, $param_email, $param_phone, $param_created_by);

                $param_user_code = $user_code;
                $param_username = $username;
                $param_password_hash = password_hash($password, PASSWORD_DEFAULT);
                $param_role = $role;
                $param_customer_type = $customer_type;
                $param_email = $email;
                $param_phone = $phone;
                $param_created_by = $logged_in_user_id;

                if (!mysqli_stmt_execute($stmt)) {
                    throw new Exception("Error creating user: " . mysqli_error($conn));
                }
                $new_user_id = mysqli_insert_id($conn);
                mysqli_stmt_close($stmt);

                // Assign locations for warehouse managers
                if ($role == 'warehouse_manager' && !empty($assigned_locations)) {
                    $sql_assign_loc = "INSERT INTO user_assigned_locations (user_id, location_id) VALUES (?, ?)";
                    if ($stmt_assign = mysqli_prepare($conn, $sql_assign_loc)) {
                        foreach ($assigned_locations as $loc_id) {
                            mysqli_stmt_bind_param($stmt_assign, "ii", $new_user_id, $loc_id);
                            if (!mysqli_stmt_execute($stmt_assign)) {
                                throw new Exception("Error assigning location " . $loc_id . " to user " . $new_user_id . ": " . mysqli_error($conn));
                            }
                        }
                        mysqli_stmt_close($stmt_assign);
                    } else {
                        throw new Exception("Error preparing location assignment statement: " . mysqli_error($conn));
                    }
                }

                mysqli_commit($conn);
                $_SESSION['success_message'] = "User '" . htmlspecialchars($username) . "' created successfully!";
                header("location: " . BASE_URL . "users/index.php");
                exit();
            } else {
                throw new Exception("Error preparing insert statement: " . mysqli_error($conn));
            }
        } catch (Exception $e) {
            mysqli_rollback($conn);
            $_SESSION['error_message'] = "Transaction failed: " . $e->getMessage();
            error_log("Error creating user (transaction rolled back): " . $e->getMessage());
        }
    } else {
        // Combine all errors for display
        $error_message = implode("<br>", array_filter([$username_err, $password_err, $confirm_password_err, $role_err, $email_err, $phone_err, $customer_type_err]));
        if ($role == 'warehouse_manager' && empty($assigned_locations)) { // Add specific error for unassigned warehouse manager
             $error_message .= "<br>Warehouse Manager must be assigned to at least one warehouse.";
        }
    }
}
?>

<?php include '../includes/head.php'; ?>
<style>
.form-label i {
    color: #6c757d;
}

.form-text i {
    color: #0d6efd;
}
</style>
<?php include '../includes/sidebar.php'; ?>

<div class="content">
    <?php include '../includes/navbar.php'; ?>

    <div class="container-fluid mt-4">
        <h2 class="mb-4">Add New User</h2>
        <a href="<?php echo BASE_URL; ?>users/index.php" class="btn btn-secondary mb-3"><i class="fas fa-arrow-left"></i> Back to User List</a>

        <?php
        if (isset($_SESSION['error_message'])) {
            echo '<div class="alert alert-danger">' . $_SESSION['error_message'] . '</div>';
            unset($_SESSION['error_message']);
        }
        if (isset($_SESSION['success_message'])) {
            echo '<div class="alert alert-success">' . $_SESSION['success_message'] . '</div>';
            unset($_SESSION['success_message']);
        }
        // Display combined local validation errors if present
        if (isset($error_message) && !empty($error_message) && $_SERVER["REQUEST_METHOD"] == "POST") {
            echo '<div class="alert alert-danger">' . $error_message . '</div>';
        }
        ?>

        <div class="card p-4 shadow-sm">
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post">
                <div class="mb-3">
                    <label for="username" class="form-label">Username <span class="text-danger">*</span></label>
                    <input type="text" name="username" id="username" class="form-control <?php echo (!empty($username_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($username); ?>" required>
                    <div class="invalid-feedback"><?php echo $username_err; ?></div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="password" class="form-label">Password <span class="text-danger">*</span></label>
                        <input type="password" name="password" id="password" class="form-control <?php echo (!empty($password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($password); ?>" required>
                        <div class="invalid-feedback"><?php echo $password_err; ?></div>
                        <small class="form-text text-muted">
                            Must be 8+ chars, 1 uppercase, 1 lowercase, 1 digit, 1 special char.
                        </small>
                    </div>
                    <div class="col-md-6 mb-3">
                        <label for="confirm_password" class="form-label">Confirm Password <span class="text-danger">*</span></label>
                        <input type="password" name="confirm_password" id="confirm_password" class="form-control <?php echo (!empty($confirm_password_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($confirm_password); ?>" required>
                        <div class="invalid-feedback"><?php echo $confirm_password_err; ?></div>
                    </div>
                </div>

                <div class="mb-3">
                    <label for="email" class="form-label">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email" id="email" class="form-control <?php echo (!empty($email_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($email); ?>" required>
                    <div class="invalid-feedback"><?php echo $email_err; ?></div>
                </div>

                <div class="mb-3">
                    <label for="phone" class="form-label">Phone <span class="text-danger">*</span></label>
                    <input type="text" name="phone" id="phone" class="form-control <?php echo (!empty($phone_err)) ? 'is-invalid' : ''; ?>" value="<?php echo htmlspecialchars($phone); ?>" required>
                    <div class="invalid-feedback"><?php echo $phone_err; ?></div>
                </div>

                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label for="role" class="form-label">Role <span class="text-danger">*</span></label>
                        <select name="role" id="role" class="form-select <?php echo (!empty($role_err)) ? 'is-invalid' : ''; ?>" required>
                            <option value="">Select Role</option>
                            <option value="admin" <?php echo ($role == 'admin') ? 'selected' : ''; ?>>Admin</option>
                            <option value="farm_manager" <?php echo ($role == 'farm_manager') ? 'selected' : ''; ?>>Farm Manager</option>
                            <option value="warehouse_manager" <?php echo ($role == 'warehouse_manager') ? 'selected' : ''; ?>>Warehouse Manager</option>
                            <option value="logistics_manager" <?php echo ($role == 'logistics_manager') ? 'selected' : ''; ?>>Logistics Manager</option>
                            <option value="driver" <?php echo ($role == 'driver') ? 'selected' : ''; ?>>Driver</option>
                            <option value="customer" <?php echo ($role == 'customer') ? 'selected' : ''; ?>>Customer</option>
                        </select>
                        <div class="invalid-feedback"><?php echo $role_err; ?></div>
                    </div>

                    <div class="col-md-6 mb-3" id="conditional_fields_container">
                        <div id="customer_type_group" style="display: none;">
                            <label for="customer_type" class="form-label">Customer Type <span class="text-danger">*</span></label>
                            <select name="customer_type" id="customer_type" class="form-select <?php echo (!empty($customer_type_err)) ? 'is-invalid' : ''; ?>">
                                <option value="">Select Type</option>
                                <option value="direct" <?php echo ($customer_type == 'direct') ? 'selected' : ''; ?>>Direct Customer</option>
                                <option value="retailer" <?php echo ($customer_type == 'retailer') ? 'selected' : ''; ?>>Retailer (30% Discount)</option>
                            </select>
                            <div class="invalid-feedback"><?php echo $customer_type_err; ?></div>
                        </div>

                        <div id="assigned_locations_group" style="display: none;">
                            <label for="assigned_locations" class="form-label">
                                <i class="fas fa-warehouse me-2"></i>Assigned Warehouse <span class="text-danger">*</span>
                            </label>
                            <select name="assigned_locations" id="assigned_locations" class="form-select <?php echo (!empty($email_err) && $role == 'warehouse_manager' && empty($assigned_locations)) ? 'is-invalid' : ''; ?>" required>
                                <option value="">Select Warehouse</option>
                                <?php foreach ($all_locations_options as $loc): ?>
                                    <?php if ($loc['type'] == 'warehouse'): ?>
                                        <option value="<?php echo htmlspecialchars($loc['location_id']); ?>"
                                            <?php echo (in_array($loc['location_id'], $assigned_locations)) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($loc['name']); ?>
                                        </option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback">
                                <?php echo ($role == 'warehouse_manager' && empty($assigned_locations)) ? 'Warehouse Manager must be assigned to a warehouse.' : ''; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-success"><i class="fas fa-user-plus"></i> Create User</button>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const roleSelect = document.getElementById('role');
    const customerTypeGroup = document.getElementById('customer_type_group');
    const customerTypeSelect = document.getElementById('customer_type');
    const assignedLocationsGroup = document.getElementById('assigned_locations_group');
    const assignedLocationsSelect = document.getElementById('assigned_locations');

    function toggleConditionalFieldsVisibility() {
        // Hide both initially
        customerTypeGroup.style.display = 'none';
        assignedLocationsGroup.style.display = 'none';

        // Reset 'required' and selected values for safety
        customerTypeSelect.removeAttribute('required');
        assignedLocationsSelect.removeAttribute('required');
        assignedLocationsSelect.value = '';

        // Show relevant group based on role
        if (roleSelect.value === 'customer') {
            customerTypeGroup.style.display = 'block';
            customerTypeSelect.setAttribute('required', 'required');
            customerTypeSelect.value = 'direct'; // Default to direct if chosen
        } else if (roleSelect.value === 'warehouse_manager') {
            assignedLocationsGroup.style.display = 'block';
            assignedLocationsSelect.setAttribute('required', 'required');
        }
    }

    // Initial call on page load
    toggleConditionalFieldsVisibility();

    // Event listener for role change
    roleSelect.addEventListener('change', toggleConditionalFieldsVisibility);
});
</script>