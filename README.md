# Game Tracker

A web-based game collection management application built with PHP, SQLite, and vanilla JavaScript.

## Features

- **Game Collection Management**: Add, edit, and delete games from your collection
- **Physical/Digital Tracking**: Tag games as physical or digital copies
- **Image Management**: Upload front/back covers and extra photos
- **Advanced Filtering**: Filter by platform, genre, type, and play status
- **Search**: Quick search across titles, platforms, and genres
- **Multiple Views**: Switch between list and grid (coverflow-style) views
- **Price Tracking**: Automatic price fetching from Pricecharting API
- **Metacritic Integration**: Optional Metacritic rating scraping
- **Custom Backgrounds**: Upload and customize your app background
- **Responsive Design**: Works perfectly on desktop, tablet, and mobile devices
- **Authentication**: Secure login system to protect your collection

## Requirements

- PHP 7.4 or higher
- SQLite (included with PHP)
- Web server (Apache/Nginx) or PHP built-in server for development

## Installation

1. Clone or download this repository to your web server directory

2. Ensure the following directories are writable:
   - `database/` (for SQLite database)
   - `uploads/` (for uploaded images)
   - `uploads/covers/` (for game covers)
   - `uploads/extras/` (for extra photos)

3. The database and directories will be created automatically on first run

## Running on macOS (Development)

### Local Development

```bash
cd /path/to/gameTracker
php -S localhost:8000 router.php
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

- **Username**: `admin`
- **Password**: `admin`

**Important**: Change the password after first login! You can do this by editing the database directly or modifying the code.

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

## Security Notes

- Change the default admin password immediately
- The app uses PHP sessions for authentication
- SQL injection is prevented using PDO prepared statements
- XSS protection via `htmlspecialchars()` on output
- File uploads are validated (type and size)
- Consider adding HTTPS when deploying

## Future Enhancements

- User management (multiple users)
- Export/import functionality
- Statistics and charts
- Wishlist feature
- Play time tracking
- Migration to PostgreSQL for production

## Troubleshooting

**Database errors**: Ensure `database/` directory is writable
**Image upload fails**: Check `uploads/` directory permissions
**Pricecharting not working**: API may require registration or have rate limits
**Metacritic scraping fails**: Website structure may have changed (scraping is fragile)

## License

This project is open source and available for personal use.

