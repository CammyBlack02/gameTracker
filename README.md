# gameTracker

A secure, multi-user web application for managing personal game collections. Built with PHP, MySQL, and vanilla JavaScript, featuring enterprise-level security and a modern, responsive interface.

> **Note**: This application has been migrated from SQLite to MySQL and includes comprehensive security hardening for production use. See [SETUP-GUIDE.md](SETUP-GUIDE.md) for complete setup instructions.

## Features

### Core Functionality
- **Multi-User Support**: Each user has their own private collection
- **Game Collection Management**: Add, edit, and delete games with rich metadata
- **Image Management**: Local storage + external URL support for cover images
- **Advanced Filtering**: Filter by platform, genre, condition, completion status
- **Multiple Views**: Grid, list, and coverflow views
- **Spin Wheel**: Random game selection with customizable filters
- **Completion Tracking**: Track when and how long games took to complete
- **Statistics**: Collection analytics and visualizations
- **GameEye Import**: Bulk import from GameEye CSV files

### Security Features
- **HTTPS/SSL**: Full encryption with Let's Encrypt certificates
- **Rate Limiting**: Protection against brute force attacks
- **Fail2ban**: Automatic IP banning for suspicious activity
- **Security Logging**: Comprehensive event tracking
- **CSRF Protection**: Token-based request validation
- **XSS Protection**: HTML escaping throughout
- **Secure File Uploads**: MIME type validation, size limits, dimension checks
- **Session Security**: Secure cookies, timeout, regeneration

### User Management
- **Admin Dashboard**: User management and password resets
- **User Profiles**: Browse other users' collections
- **Role-Based Access**: Admin and user roles
- **Registration**: Open registration (configurable)

## Requirements

- **Server**: Ubuntu 22.04+ (or similar Linux distribution)
- **PHP**: 8.3+ with extensions: `php-fpm`, `php-mysql`, `php-curl`, `php-gd`
- **Database**: MySQL 8.0+
- **Web Server**: Nginx (recommended) or Apache
- **SSL**: Certbot for Let's Encrypt certificates
- **Network**: Router with port forwarding capability (UniFi recommended)

## Quick Start

For complete setup instructions, see **[SETUP-GUIDE.md](SETUP-GUIDE.md)**.

### Basic Installation

1. **Clone repository**:
   ```bash
   git clone https://github.com/yourusername/gameTracker.git
   cd gameTracker
   ```

2. **Configure database** in `includes/config.php`:
   ```php
   define('DB_HOST', 'localhost');
   define('DB_NAME', 'gameTracker');
   define('DB_USER', 'your_username');
   define('DB_PASS', 'your_password');
   ```

3. **Set permissions**:
   ```bash
   sudo chown -R www-data:www-data /var/www/gameTracker
   sudo chmod 600 includes/config.php
   sudo chmod 755 uploads uploads/covers uploads/extras
   ```

4. **Configure Nginx** (see `nginx-gameTracker.conf`)

5. **Set up SSL**:
   ```bash
   sudo certbot --nginx -d yourdomain.com
   ```

6. **Access the site** and change default admin credentials!

For detailed setup, security hardening, and network configuration, see [SETUP-GUIDE.md](SETUP-GUIDE.md).

## Running on macOS (Development)

### Local Development

```bash
cd /path/to/gameTracker
php -S localhost:8000 router.php
```

**Note**: If you need to upload images larger than 2MB, use the included `php.ini` file:
```bash
php -c php.ini -S localhost:8000 router.php
```

Then open your browser to `http://localhost:8000`

### Access from iPhone (Recommended: ngrok)

**The easiest way to access from iPhone is using ngrok:**

1. **Install ngrok** (if not already installed):
   ```bash
   brew install ngrok/ngrok/ngrok
   ```

2. **Sign up for free** at https://dashboard.ngrok.com/signup

3. **Configure ngrok:**
   ```bash
   ngrok config add-authtoken YOUR_TOKEN_HERE
   ```
   (Get your token from https://dashboard.ngrok.com/get-started/your-authtoken)

4. **Start PHP server** (in one terminal):
   ```bash
   php -S localhost:8000 router.php
   ```

5. **Start ngrok** (in another terminal):
   ```bash
   ngrok http 8000
   ```

6. **Use the HTTPS URL** shown by ngrok on your iPhone

**For other options** (MAMP, self-signed certificates, etc.), see `HTTPS-SETUP.md`

## Default Login

⚠️ **Security Note**: Default credentials are created on first run. **Change them immediately** using the admin dashboard or `change-admin-credentials.php` script.

**Important**: The login page no longer displays default credentials for security.

## Usage

1. **Login**: Use the default credentials to log in
2. **Add Games**: Click "Add Game" button to add your first game
3. **View Games**: Browse your collection in list or grid view
4. **Filter & Search**: Use the toolbar to filter and search your games
5. **Edit Games**: Click on any game to view details and edit
6. **Upload Images**: Add cover images and extra photos when editing games
7. **Customize**: Go to Settings to upload a custom background image

## File Structure

```
gameTracker/
├── index.php              # Login page
├── dashboard.php          # Main collection view
├── game-detail.php        # Individual game details
├── settings.php           # App settings
├── api/                   # API endpoints
│   ├── auth.php          # Authentication
│   ├── games.php           # Game CRUD operations
│   ├── upload.php        # Image uploads
│   ├── pricecharting.php # Price fetching
│   ├── metacritic.php    # Metacritic scraping
│   └── settings.php      # Settings management
├── includes/             # PHP includes
│   ├── config.php        # Database config
│   ├── auth-check.php    # Auth validation
│   └── functions.php     # Helper functions
├── css/
│   └── style.css        # Main stylesheet
├── js/                   # JavaScript files
│   ├── main.js          # Core functionality
│   ├── games.js         # Game management
│   └── filters.js       # Filtering logic
├── database/            # SQLite database (auto-created)
└── uploads/             # Uploaded images (auto-created)
```

## API Endpoints

All API endpoints return JSON responses:

- `api/auth.php?action=login` - POST: Login
- `api/auth.php?action=logout` - GET: Logout
- `api/games.php?action=list` - GET: List all games
- `api/games.php?action=get&id=X` - GET: Get game details
- `api/games.php?action=create` - POST: Create game
- `api/games.php?action=update` - POST: Update game
- `api/games.php?action=delete&id=X` - GET: Delete game
- `api/upload.php` - POST: Upload image
- `api/pricecharting.php?title=X&platform=Y` - GET: Fetch price
- `api/metacritic.php?title=X&platform=Y` - GET: Fetch Metacritic rating

## Deployment to Ubuntu Server

1. Copy files to your server (e.g., `/var/www/gametracker/`)
2. Set proper permissions:
   ```bash
   chmod -R 755 /var/www/gametracker
   chmod -R 777 /var/www/gametracker/database
   chmod -R 777 /var/www/gametracker/uploads
   ```
3. Configure your web server (Apache/Nginx) to point to the directory
4. Access via VPN: `http://[server-ip]/gametracker/`

## Security

This application includes comprehensive security measures:

- ✅ **HTTPS/SSL** with Let's Encrypt certificates
- ✅ **Rate limiting** (application + Nginx levels)
- ✅ **Fail2ban** for brute force protection
- ✅ **SQL injection protection** (prepared statements)
- ✅ **XSS protection** (HTML escaping)
- ✅ **CSRF protection** (tokens + SameSite cookies)
- ✅ **Secure file uploads** (MIME validation, size limits)
- ✅ **Session security** (secure cookies, timeout)
- ✅ **Security logging** and monitoring
- ✅ **Search engine blocking** (private site)

See [SECURITY-ASSESSMENT.md](SECURITY-ASSESSMENT.md) for detailed security analysis.

## Documentation

- **[SETUP-GUIDE.md](SETUP-GUIDE.md)** - Complete setup instructions
- **[SECURITY-ASSESSMENT.md](SECURITY-ASSESSMENT.md)** - Security analysis
- **[SECURITY-SETUP.md](SECURITY-SETUP.md)** - Security hardening guide
- **[UNIFI-SETUP.md](UNIFI-SETUP.md)** - UniFi network configuration

## Credits

Developed with assistance from AI coding assistants (Claude/Anthropic via Cursor IDE) for implementation, security hardening, and documentation.

## Troubleshooting

**Database errors**: Ensure `database/` directory is writable
**Image upload fails**: Check `uploads/` directory permissions
**Pricecharting not working**: API may require registration or have rate limits
**Metacritic scraping fails**: Website structure may have changed (scraping is fragile)

## License

This project is open source and available for personal use.

