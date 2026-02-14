![Logo](https://myvivarium.online/images/logo1.jpg)

# MyVivarium v2

![Project Status](https://img.shields.io/badge/status-active-brightgreen) [![LGPL License](https://img.shields.io/badge/License-LGPL--3.0-blue.svg)](https://choosealicense.com/licenses/lgpl-3.0/)

[![Visit Demo Site](https://img.shields.io/badge/Visit-Demo%20Site-blue?style=for-the-badge)](https://demo.myvivarium.online)

![PHP](https://img.shields.io/badge/php-%23777BB4.svg?&style=for-the-badge&logo=php&logoColor=white) ![HTML](https://img.shields.io/badge/html5-%23E34F26.svg?&style=for-the-badge&logo=html5&logoColor=white) ![CSS](https://img.shields.io/badge/css3-%231572B6.svg?&style=for-the-badge&logo=css3&logoColor=white) ![JavaScript](https://img.shields.io/badge/javascript-%23F7DF1E.svg?&style=for-the-badge&logo=javascript&logoColor=black) ![Font Awesome](https://img.shields.io/badge/font%20awesome-%23339AF0.svg?&style=for-the-badge&logo=font-awesome&logoColor=white) ![Bootstrap](https://img.shields.io/badge/bootstrap-%23563D7C.svg?&style=for-the-badge&logo=bootstrap&logoColor=white)

MyVivarium is an online platform designed to manage your vivarium effectively. It provides features such as user registration, profile management, lab management, and real-time environmental monitoring with IoT sensors.

**MyVivarium-2** is the enhanced version with improved security, new features, and a better user experience. The original [MyVivarium](https://github.com/myvivarium/MyVivarium) repository is preserved as the initial release.

## Table of Contents
- [What's New in v2](#whats-new-in-v2)
- [Features](#features)
- [Screenshot](#screenshot)
- [Installation](#installation)
- [Upgrading from v1](#upgrading-from-v1)
- [Usage](#usage)
- [File Reference](#file-reference)
- [Citations](#citations)
- [Contributing](#contributing)
- [License](#license)

## What's New in v2

### New Features
- **Cage Archiving** -- Soft-delete cages instead of permanent deletion, with restore capability
- **Cage ID Editing** -- Rename cage IDs with automatic propagation across all related tables
- **Cage Duplication** -- Clone an existing cage to quickly create a similar one (copies strain, IACUC, users, location, mice)
- **Move Mouse Between Cages** -- Transfer mice from one holding cage to another with validation and quantity tracking
- **Configurable Pagination** -- Choose 10, 20, 30, or 50 cages per page on dashboards
- **Column Sorting** -- Sort cage lists by cage ID in ascending or descending order
- **Location Tracking** -- Room and rack fields on all cage types
- **Genotype Fields** -- Track genotype on holding cages and male/female genotype on breeding cages
- **Parent Cage Tracking** -- Track male and female source/parent cage on breeding cages
- **Flexible Cage Creation** -- Only cage ID is required; all other fields are optional
- **Custom Strains** -- "None / Not Applicable" and "Custom" strain options with free-text input
- **Vivarium Manager Role** -- Dedicated role with maintenance notes oversight across all cages
- **Activity/Audit Log** -- Track who changed what and when (create, edit, archive, restore, delete, rename, transfer, role changes)
- **Cage Lineage View** -- Visual tree with searchable Select2 dropdown for cage selection
- **Date Range Filters** -- Filter maintenance notes by date range with From/To date inputs
- **Dark Mode** -- Full dark mode support across all pages using Bootstrap 5.3 CSS variables (preference saved in localStorage)
- **Dashboard Stats** -- Home page shows active vs. archived cage counts
- **Archive Workflow** -- Intuitive Restore and Delete Forever buttons on archived cages with double confirmation for permanent deletion

### Security Improvements
- SQL injection fixes (prepared statements throughout)
- CSRF token validation on all state-changing operations
- XSS prevention with proper output encoding
- Session security hardening (HttpOnly, SameSite, 30-min timeout, ID regeneration)
- Authentication checks on all API endpoints
- Removed deprecated `FILTER_SANITIZE_STRING` (PHP 8.2+ compatible)
- Fixed double-escaping bugs with `mysqli_real_escape_string` + `bind_param`
- CLI-only guard on `process_reminders.php`
- Standardized on Bootstrap 5.3.3 (removed all Bootstrap 4 conflicts)

### UI/UX Improvements
- Replaced all hardcoded CSS colors with Bootstrap CSS variables for consistent dark mode
- Migrated from Bootstrap 4 `.form-group` class to Bootstrap 5 `mb-3` utility
- Button tooltips on dashboard action buttons (View, Tasks, Edit, Archive, Restore, Delete Forever)
- Tooltips properly initialize on dynamically loaded AJAX content
- Section cards with consistent styling across all view pages
- Consistent action button sizing (34x34px) across all tables
- Footer positioning fix (no longer overlaps content on long pages)
- Dark mode support for Select2 dropdowns, form controls, alerts, and modals

### Bug Fixes
- Fixed broken cage permission checks (now uses `cage_users` junction table)
- Fixed login error messages not displaying
- Fixed account lock time mismatch
- Prevented username enumeration on login
- Fixed premature `</body></html>` in header.php
- Added search debounce (300ms) to prevent excessive AJAX requests
- Fixed footer rendering in middle of page content (changed `height: 100%` to `min-height: 100vh`)

## Features
- User registration and login with email verification
- User profile management and password reset
- Admin functionalities for managing users and labs
- Vivarium Manager role for maintenance oversight
- Cage archiving with restore and permanent delete options
- Cage duplication (clone) for quick cage creation
- Mouse transfer between holding cages
- Cage lineage view (visual parent-child tree)
- Activity/audit log with search, date range, and entity filters
- Configurable pagination and sorting on cage dashboards
- Date range filters on maintenance notes
- Location tracking (room/rack) and genotype fields
- Dark mode with localStorage persistence
- Real-time environmental monitoring using IoT sensors ([RPi-IoT Repository](https://github.com/myvivarium/RPi-IoT))
- Secure and compliant data management

![image](https://myvivarium.online/images/illustration.jpg)

## Installation

### 1. DigitalOcean One-Click Install (Recommended)

[![DigitalOcean Referral Badge](https://web-platforms.sfo2.cdn.digitaloceanspaces.com/WWW/Badge%203.svg)](https://www.digitalocean.com/?refcode=fdb1aa3adb7d&utm_campaign=Referral_Invite&utm_medium=Referral_Program&utm_source=badge)

#### Steps:

1. **Sign up for a DigitalOcean account** using the referral link above to get your credits.

2. **Create a PHPMyAdmin Droplet**:
   - Deploy DigitalOcean's [PHPMyAdmin Droplet](https://marketplace.digitalocean.com/apps/phpmyadmin) (1-click setup: PHPMyAdmin, MySQL, Apache, PHP, Certbot).
   - Choose your **Region** and **Datacenter**.
   - Select **Basic** plan with **Regular (1 GB / 1 CPU)**.
   - Set up **SSH Key** or **password** access.
   - Click **Create Droplet**.

3. **Access the Droplet**:
   - Use the console in the DigitalOcean dashboard.
   - If you have a domain, point an A record to the droplet's IPv4 address.

4. **Download and run the installation script**:
   ```bash
   curl -O https://raw.githubusercontent.com/myvivarium/MyVivarium/main/setup/setup.sh
   chmod +x setup.sh
   sudo ./setup.sh
   ```

5. **Follow the prompts** to configure email, domain, database password, and SMTP settings.

### 2. Custom Installation

#### Prerequisites
- PHP 8.1+
- MySQL 8.0+
- Apache 2.4+
- Composer
- [LAMP Stack Tutorial](https://www.digitalocean.com/community/tutorials/how-to-install-lamp-stack-on-ubuntu)

#### Steps

1. **Clone the repository:**
    ```bash
    git clone https://github.com/robinson-vidva/MyVivarium-2.git
    cd MyVivarium-2
    ```

2. **Set up the environment configuration:**
    ```bash
    cp .env.example .env
    ```
    Update `.env` with your database and SMTP settings (see [Configuration](#configuration)).

3. **Place files in the web server directory:**
    ```bash
    mv * /path/to/your/public_html/
    cp .env /path/to/your/public_html/
    ```

4. **Install dependencies:**
    ```bash
    composer install
    ```

5. **Set up the database:**
    ```bash
    mysql -u yourusername -p
    ```
    ```sql
    CREATE DATABASE myvivarium;
    USE myvivarium;
    SOURCE /path/to/your/public_html/database/schema.sql;
    ```

6. **Set up cron jobs:**
    ```bash
    # Process reminders every 5 minutes
    */5 * * * * php /path/to/your/public_html/process_reminders.php
    # Send queued emails every minute
    * * * * * php /path/to/your/public_html/send_email.php
    ```

7. **Set ownership and permissions:**
    ```bash
    sudo chown -R www-data:www-data /path/to/your/public_html
    sudo chmod -R 755 /path/to/your/public_html
    ```

#### Configuration

##### Database Configuration
```bash
DB_HOST=localhost
DB_USERNAME=username
DB_PASSWORD=password
DB_DATABASE=myvivarium
```

##### SMTP Configuration
```bash
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=username
SMTP_PASSWORD=password
SMTP_ENCRYPTION=tls
SENDER_EMAIL=sender@example.com
SENDER_NAME=MyVivarium
```

## Upgrading from v1

If you're upgrading from the original MyVivarium, you have two options:

### Option A: In-Place SQL Migration (Recommended)
Run the migration script directly on your existing database:
```bash
# Back up first!
mysqldump -u root -p myvivarium > backup_before_v2.sql

# Apply migration
mysql -u root -p myvivarium < database/migrate_v1_to_v2.sql
```

### Option B: Interactive Shell Migration
Use the interactive script to migrate data from one database to another:
```bash
chmod +x database/migrate_v1_to_v2.sh
./database/migrate_v1_to_v2.sh
```
This will prompt for source/destination database credentials, create automatic backups, and migrate data table-by-table.

### What the Migration Does
| Change | Details |
|--------|---------|
| Vivarium Manager role | Auto-assigns role to users with matching positions |
| Cage status column | Adds `status` ENUM ('active', 'archived') with default 'active' |
| Location fields | Adds `room` and `rack` to cages |
| Genotype (holding) | Adds `genotype` to holding table |
| Genotype (breeding) | Adds `male_genotype` and `female_genotype` to breeding table |
| Parent cage (breeding) | Adds `male_parent_cage` and `female_parent_cage` to breeding table |
| Optional fields | Makes DOB, parent cage, cross, male/female IDs nullable |

> **Note**: If upgrading from an earlier v2 install, also run `database/migrate_add_parent_cage.sql` to add the parent cage columns.

See `database/README.md` for full migration details.

## Screenshot

![image](https://myvivarium.online/images/myvivarium.gif)

## Usage

1. Access the application at `http://yourdomain.com`
2. Register a new user or log in with existing credentials.
3. Manage your lab, users, and monitor environmental conditions in real-time.

### Default Admin User
- **Email**: `admin@myvivarium.online`
- **Password**: `P@ssw0rd`

**Important**: Delete this default admin user and create a new admin after initial setup.

### User Roles
| Role | Permissions |
|------|------------|
| **Admin** | Full access: manage users, IACUC, strains, labs, export data, all cage operations |
| **Vivarium Manager** | View/add/edit/delete maintenance notes across all cages, standard cage operations |
| **User** | Standard cage operations on assigned cages only |

## File Reference

### Core
| File | Description |
|------|-------------|
| `dbcon.php` | Database connection |
| `config.php` | SMTP configuration |
| `session_config.php` | Secure session settings (HttpOnly, HTTPS, CSRF, 30-min timeout) |
| `header.php` | Navigation header |
| `footer.php` | Footer with dynamic lab name |
| `message.php` | Session message display |

### Authentication
| File | Description |
|------|-------------|
| `index.php` | Login page |
| `register.php` | User registration with email verification |
| `forgot_password.php` | Password reset emails |
| `reset_password.php` | Password reset form |
| `confirm_email.php` | Email verification |
| `logout.php` | Session destruction |

### Dashboard & Home
| File | Description |
|------|-------------|
| `home.php` | Home page with cage stats (active/archived counts) |
| `user_profile.php` | User profile management |

### Admin
| File | Description |
|------|-------------|
| `manage_users.php` | User management (roles: admin, vivarium_manager, user) |
| `manage_lab.php` | Lab settings |
| `manage_strain.php` | Strain management |
| `manage_iacuc.php` | IACUC protocol management |
| `export_data.php` | CSV export of all tables |

### Holding Cages
| File | Description |
|------|-------------|
| `hc_dash.php` | Dashboard with pagination, sort, archive toggle |
| `hc_fetch_data.php` | AJAX data fetch with dynamic limit/sort/filter |
| `hc_addn.php` | Add cage (room, rack, genotype, custom strain) |
| `hc_view.php` | View cage details |
| `hc_edit.php` | Edit cage (includes cage ID rename) |
| `hc_drop.php` | Archive/restore/permanent delete |
| `hc_slct_crd.php` | Select cages for card printing |
| `hc_prnt_crd.php` | Print cage cards |

### Breeding Cages
| File | Description |
|------|-------------|
| `bc_dash.php` | Dashboard with pagination, sort, archive toggle |
| `bc_fetch_data.php` | AJAX data fetch with dynamic limit/sort/filter |
| `bc_addn.php` | Add cage (room, rack, genotypes, custom strain) |
| `bc_view.php` | View cage details |
| `bc_edit.php` | Edit cage (includes cage ID rename) |
| `bc_drop.php` | Archive/restore/permanent delete |
| `bc_slct_crd.php` | Select cages for card printing |
| `bc_prnt_crd.php` | Print cage cards |

### Vivarium Management
| File | Description |
|------|-------------|
| `vivarium_manager_notes.php` | Maintenance notes CRUD with search, date range filter, pagination, print |
| `maintenance.php` | Add maintenance records from cage view |
| `activity_log.php` | Activity/audit log viewer with search, date range, entity type filter |
| `log_activity.php` | Helper function for recording audit trail entries |
| `cage_lineage.php` | Visual tree view of parent-child cage relationships |
| `mouse_transfer.php` | Backend for transferring mice between holding cages |

### Tasks & Reminders
| File | Description |
|------|-------------|
| `manage_tasks.php` | Task management (add/edit/delete) |
| `get_task.php` | Retrieve task details (AJAX) |
| `manage_reminder.php` | Reminder management |
| `get_reminder.php` | Retrieve reminder details (AJAX) |
| `process_reminders.php` | Process scheduled reminders (cron job, CLI only) |
| `send_email.php` | Send queued emails (cron job) |

### Other
| File | Description |
|------|-------------|
| `nt_app.php` | Sticky notes application |
| `nt_add.php` / `nt_edit.php` / `nt_rmv.php` | Sticky note CRUD |
| `iot_sensors.php` | IoT sensor data display |
| `delete_file.php` | File attachment deletion |

### Database
| File | Description |
|------|-------------|
| `database/schema.sql` | Full v2 database schema (use for fresh installs) |
| `database/migrate_v1_to_v2.sql` | In-place SQL migration from v1 to v2 |
| `database/migrate_v1_to_v2.sh` | Interactive shell migration script |
| `database/migrate_add_parent_cage.sql` | Adds male/female parent cage columns to breeding table |
| `database/README.md` | Detailed migration documentation |

## Demo Website

[![Visit Demo Site](https://img.shields.io/badge/Visit-Demo%20Site-blue?style=for-the-badge)](https://demo.myvivarium.online)

- **Email**: `admin@myvivarium.online`
- **Password**: `P@ssw0rd`

**Disclaimer**: This is a demo site for exploring features. All data will be cleared periodically. Do not enter sensitive information.

## Citations

If you use this code, please cite the following paper:

Vidva, R., Raza, M. A., Prabhakaran, J., Sheikh, A., Sharp, A., Ott, H., Moore, A., Fleisher, C., Pitychoutis, P. M., Nguyen, T. V., & Sathyanesan, A. (2024). MyVivarium: A cloud-based lab animal colony management application with near-realtime ambient sensing. *bioRxiv*. https://doi.org/10.1101/2024.08.10.607395

### BibTeX

```bibtex
@article {Vidva2024.08.10.607395,
	author = {Vidva, Robinson and Raza, Mir Abbas and Prabhakaran, Jaswant and Sheikh, Ayesha and Sharp, Alaina and Ott, Hayden and Moore, Amelia and Fleisher, Christopher and Pitychoutis, Pothitos M. and Nguyen, Tam V. and Sathyanesan, Aaron},
	title = {MyVivarium: A cloud-based lab animal colony management application with near-realtime ambient sensing},
	elocation-id = {2024.08.10.607395},
	year = {2024},
	doi = {10.1101/2024.08.10.607395},
	publisher = {Cold Spring Harbor Laboratory},
	URL = {https://www.biorxiv.org/content/early/2024/08/10/2024.08.10.607395},
	eprint = {https://www.biorxiv.org/content/early/2024/08/10/2024.08.10.607395.full.pdf},
	journal = {bioRxiv}
}
```

## Contributing
We welcome contributions to improve MyVivarium. Please follow these steps:

1. Fork the repository.
2. Create a new branch: `git checkout -b feature/your-feature-name`
3. Commit your changes: `git commit -m 'Add some feature'`
4. Push to the branch: `git push origin feature/your-feature-name`
5. Open a pull request.

## License
This project is licensed under the LGPL License - see the [LICENSE](LICENSE) file for details.
