# Proactive Maintenance — WordPress Plugin

Snapshot site state, create database backups, and publish formatted maintenance reports to Google Drive — all from a single WP Admin dashboard.

---

## Installation

1. Upload the `proactive-maintenance` folder to `/wp-content/plugins/`.
2. SSH into the site (or use a local terminal if developing locally) and run:

   ```bash
   cd wp-content/plugins/proactive-maintenance
   composer install --no-dev --optimize-autoloader
   ```

3. Activate the plugin in **Plugins → Installed Plugins**.
4. Go to **Maintenance → Settings** and complete the one-time Google Drive setup below.

---

## Google Drive Setup (one-time)

1. Open [Google Cloud Console](https://console.cloud.google.com/) and create or select a project.
2. Enable **Google Drive API** and **Google Docs API** (APIs & Services → Library).
3. Go to **IAM & Admin → Service Accounts** → Create Service Account.
   - Download the JSON key file.
4. In your **Google Drive**, create a folder called `Proactive Maintenance`.
5. Share that folder with the service account email (e.g. `pm-plugin@your-project.iam.gserviceaccount.com`) — grant **Editor** access.
6. Open the folder in Drive and copy the folder ID from the URL:
   `https://drive.google.com/drive/folders/`**`THIS-IS-THE-FOLDER-ID`**
7. In WP Admin under **Maintenance → Settings**:
   - Enter the **Client Name** (becomes the subfolder name).
   - Paste the **Folder ID**.
   - Paste the full **Service Account JSON** contents.
   - Click **Save**, then **Test Google Drive Connection**.

---

## Maintenance Workflow

Each maintenance session, follow these four steps on the Dashboard:

| Step | Action | Notes |
|------|--------|-------|
| 1 | **Take Pre-Update Snapshot** | Records plugin/core/PHP versions before changes |
| 2 | **Run updates in WP Admin** | Do this manually at your own pace |
| 3 | **Create & Upload Backup** | DB export (.sql.gz) → uploaded to Drive |
| 4 | **Generate Report** | Compares pre/post, creates Google Doc in Drive |

Reports are saved to:
```
Proactive Maintenance / [Client Name] / YYYY-MM-DD Maintenance Report
```

---

## Report Contents

- **Summary** — site URL, date, snapshot times
- **WordPress Core** — version before/after
- **PHP & Server** — version, memory limit, execution time, etc.
- **Active Theme** — version before/after
- **Plugins** — updated, added, removed, and unchanged plugins with version diff
- **Site Health** — PHP version warnings, debug mode flags, SSL status, file editor status, inactive plugins
- **Database Backup** — filename, size, Google Drive link

---

## Requirements

- WordPress 5.6+
- PHP 7.4+
- Composer (for installation)
- Google Cloud project with Drive + Docs APIs enabled
