# Cleanup Status

## ✅ Completed Cleanup

The following files have been removed:

### Migration Scripts (Removed)
- ✅ `migrate-sqlite-to-mysql.php`
- ✅ `migrate-to-multiuser.php`
- ✅ `migrate-sega-genesis-to-mega-drive.php`

### One-time Fix Scripts (Removed)
- ✅ `fix-completion-dates.php`
- ✅ `add-release-date-field.php`
- ✅ `update-pc-digital-to-steam.php`

### Data Fetch Scripts (Removed)
- ✅ `fetch-covers-thecoverproject.php`
- ✅ `fetch-genre-description.php`
- ✅ `fetch-release-dates.php`
- ✅ `bulk-download-covers.php`
- ✅ `bulk-download-external-images.php`
- ✅ `import-completions.php`

### Redundant Documentation (Removed)
- ✅ `HTTPS-SETUP.md` (covered in SETUP-GUIDE.md)
- ✅ `QUICK-FIX-iPhone.md` (outdated)
- ✅ `TROUBLESHOOTING-CONNECTION.md` (covered in SETUP-GUIDE.md)

---

## ⚠️ Files to Review for Cleanup

## Files to Remove (One-time Migration Scripts)

These scripts were used for one-time migrations and are no longer needed:

- `migrate-sqlite-to-mysql.php` - SQLite to MySQL migration (already completed)
- `migrate-to-multiuser.php` - Single-user to multi-user migration (already completed)
- `migrate-sega-genesis-to-mega-drive.php` - Platform name migration (one-time fix)

**Recommendation**: ✅ **Remove** - These are historical and no longer needed.

---

## Files to Remove (One-time Fix Scripts)

These were used to fix specific data issues:

- `fix-completion-dates.php` - Fixed completion date formats
- `add-release-date-field.php` - Added release_date column
- `update-pc-digital-to-steam.php` - Platform name update

**Recommendation**: ✅ **Remove** - One-time fixes, no longer needed.

---

## Files to Keep (Useful for Users)

These scripts might be useful for users:

- `import-gameeye.php` - ✅ **KEEP** - Users need this to import GameEye CSV files
- `change-admin-credentials.php` - ✅ **KEEP** - Admin tool for changing credentials

**Recommendation**: ✅ **Keep** - These are useful features.

---

## Files to Consider Removing (Data Fetch Scripts)

These scripts were used to fetch data from external sources:

- `fetch-covers-thecoverproject.php` - Fetches covers from TheCoverProject
- `fetch-genre-description.php` - Fetches genre/description from TheGamesDB
- `fetch-release-dates.php` - Fetches release dates
- `bulk-download-covers.php` - Bulk downloads cover images
- `bulk-download-external-images.php` - Bulk downloads external images
- `import-completions.php` - Imports completion data

**Recommendation**: ⚠️ **Ask** - These might be useful for users to fetch metadata. Consider keeping or moving to a `scripts/` directory.

---

## Files to Remove (Data Files - Should Not Be in Repo)

These are user data files that shouldn't be in the repository:

- `11_9_2025_ge_collection.csv` - User's GameEye export
- `Games Completed 2025.csv` - User's completion data
- `Games Completed 2025.xlsx` - User's completion data (Excel)
- `GAMEYE_rpoint.ged` - User's GameEye data
- `database/games.db` - Old SQLite database
- `database/games.db.gz` - Compressed old database
- `ownership_database.db` - Old database file

**Recommendation**: ✅ **Remove** - These are personal data files. Already in `.gitignore` but should be deleted from repo if committed.

---

## Documentation Files to Consolidate

Some documentation might be redundant:

- `HTTPS-SETUP.md` - SSL setup (covered in SETUP-GUIDE.md)
- `QUICK-FIX-iPhone.md` - iPhone access (might be outdated)
- `TROUBLESHOOTING-CONNECTION.md` - Connection issues (covered in SETUP-GUIDE.md)

**Recommendation**: ⚠️ **Review** - Consider consolidating into SETUP-GUIDE.md or keeping if they have unique info.

---

## Config Files to Review

- `php.ini` - PHP configuration (might be needed for local dev)
- `router.php` - ✅ **KEEP** - Needed for local development

**Recommendation**: ⚠️ **Review** - `php.ini` might be useful for local dev, but check if it has sensitive settings.

---

## Summary

### Definitely Remove:
1. Migration scripts (3 files)
2. One-time fix scripts (3 files)
3. Data files (7 files) - Personal data, shouldn't be in repo

### Keep:
1. `import-gameeye.php` - User feature
2. `change-admin-credentials.php` - Admin tool
3. `router.php` - Local dev

### Ask About:
1. Data fetch scripts (6 files) - Might be useful for users
2. Documentation files (3 files) - Might have unique info
3. `php.ini` - Check if needed for local dev

