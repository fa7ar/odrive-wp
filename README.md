# ODrive Connector for WordPress

Connect WordPress to **ODrive** for centralized backup, storage, media, and file management across multiple storage providers.

ODrive Connector keeps WordPress lightweight. Your storage credentials and routing logic stay inside ODrive instead of being distributed across WordPress installations.

## How It Works

```text
WordPress
    ↓
ODrive Connector
    ↓
ODrive Public API
    ↓
Universal Action Layer
    ↓
Backup / Sync / Storage Pool
    ↓
Storage Providers
```

ODrive acts as the control plane.

The WordPress plugin communicates with **[ODrive](https://github.com/fa7ar/o-drive)** through a versioned API and does not require direct access to Google Drive, Cloudflare R2, Amazon S3, OneDrive, or other provider credentials.

## Features

### Connection

Connect a WordPress website to an ODrive instance using:

- ODrive URL
- Scoped API Token
- Site registration
- Connection health check

### Backup

Prepare and transfer:

- Database backup
- Uploads backup
- Themes backup
- Plugins backup
- Full-site backup

Supports manual backups and backup jobs initiated by ODrive.

### Restore

Restore supported backup components:

- Database
- Uploads
- Themes
- Plugins
- Full site

Destructive restore operations require explicit validation before execution.

### Media Integration

Provides the foundation for:

- Media backup
- Media restore
- Media offload
- Import from ODrive
- External storage URL mapping

### ODrive Storage

Storage destinations are managed by ODrive.

WordPress does **not** need credentials for individual storage providers.

Example:

```text
WordPress
    ↓
ODrive
    ↓
Backup Policy
    ↓
Storage Pool
    ↓
Smart Routing
 ┌──────┼──────┐
 ↓      ↓      ↓
R2     S3    Drive
```

## Installation

### WordPress Admin

1. Download `odrive-connector.zip`.
2. Open **Plugins → Add New → Upload Plugin**.
3. Upload the ZIP file.
4. Install and activate the plugin.
5. Open **Settings → ODrive**.

### Manual Installation

Extract the plugin into:

```text
wp-content/plugins/odrive-connector/
```

Then activate **ODrive Connector** from the WordPress Plugins screen.

## Configuration

Open:

```text
Settings → ODrive
```

Configure:

```text
ODrive URL
ODrive API Token
```

Then select:

```text
Test Connection
```

After successful validation, connect/register the WordPress site with ODrive.

The plugin should display:

- Connection status
- ODrive instance
- Site registration status
- Last health check
- Token status

## Authentication

ODrive Connector uses a scoped ODrive API token.

Recommended permissions follow least privilege and may include:

```text
wordpress.site
files.read
files.write
backup.create
backup.read
restore.create
storage.destinations.read
```

Never use provider credentials directly inside WordPress.

The plugin should never require:

```text
AWS Secret Access Key
Cloudflare R2 Secret
Google OAuth credentials
OneDrive credentials
```

These credentials remain securely managed by ODrive.

## ODrive API

The plugin communicates only through the documented/versioned ODrive Public API.

Conceptually:

```text
WordPress
    ↓
ODriveClient
    ↓
/api/v1/*
```

The internal API client handles:

- Authentication
- Site registration
- Health checks
- Backup creation
- Artifact uploads
- Progress reporting
- Failure reporting
- Restore operations
- API errors
- Timeouts
- Retries
- Rate limits

Do not scatter raw ODrive HTTP requests throughout the plugin.

## WordPress REST API

When ODrive needs to communicate with the connector, the plugin exposes narrowly scoped endpoints under:

```text
/wp-json/odrive/v1/*
```

Examples:

```text
/health
/backup
/backup/status
/restore
/restore/status
```

All privileged operations must be authenticated and validated.

ODrive Connector must never provide a generic remote-code-execution endpoint.

## Security

Security requirements include:

- WordPress capability checks
- Nonces for admin actions
- REST authentication
- Request validation
- Output escaping
- Input sanitization
- Path traversal protection
- Safe temporary-file handling
- Secret redaction
- Replay protection where applicable
- Audit/activity logging

Never expose or log:

```text
wp-config.php secrets
Database passwords
WordPress authentication salts
ODrive API tokens
Storage provider credentials
```

## Large Backups

Large backup artifacts should be streamed or processed incrementally whenever possible.

Avoid loading an entire site backup into PHP memory.

The implementation should account for common hosting limitations such as:

- PHP memory limits
- Request timeouts
- Upload limits
- Disk-space limitations

Long-running operations should expose progress and failure states rather than relying on one long HTTP request.

## Plugin Architecture

Keep components separated:

```text
ODrive Connector
├── API Client
├── Authentication
├── Site Registration
├── Health
├── Backup
├── Restore
├── Media
├── Activity
└── Admin UI
```

Use WordPress actions and filters where appropriate so future functionality can extend the connector without modifying its core.

## Admin UI

Keep the WordPress interface minimal:

```text
ODrive
├── Connection
├── Backup
├── Activity
└── Settings
```

ODrive remains the primary control plane.

Provide an **Open ODrive Dashboard** action rather than recreating the full ODrive dashboard inside WordPress.

## Development Principles

ODrive Connector should remain:

- Independent from ODrive source code
- API-driven
- Lightweight
- Modular
- Secure by default
- Compatible with normal WordPress hosting
- Storage-provider agnostic
- Extensible through WordPress hooks

The only contract between ODrive and this plugin should be the documented ODrive API.

## End-to-End Flow

A successful integration should support:

```text
Install Plugin
→ Configure ODrive URL + Token
→ Test Connection
→ Register WordPress Site
→ ODrive Detects Site
→ Health Check
→ Create Backup
→ Transfer Backup to ODrive
→ ODrive Routes to Destination / Storage Pool
→ Record Activity
```

## Requirements

- WordPress installation with REST API available
- HTTPS recommended and required for production connections
- ODrive instance with WordPress Connector API support
- Valid scoped ODrive API token
- PHP/WordPress versions supported by the current plugin release

## Roadmap

Potential future capabilities include:

- Advanced scheduled backup
- Incremental backup
- Media offloading
- One-click restore
- Backup retention policies
- Storage Pool integration
- Smart routing
- Multi-site management
- ODrive-managed automation
- Extended health monitoring

Roadmap items are not guaranteed until implemented and released.

## Relationship with ODrive

**ODrive Connector** is the WordPress-side bridge.

It should not become another storage management platform.

```text
WordPress Connector = execution endpoint

ODrive = control plane + orchestration
```

This separation keeps storage credentials, routing policies, backup policies, and provider integrations centralized in ODrive.

## License

Define the plugin license before public distribution.

If distributed through the official WordPress plugin ecosystem, ensure the selected licensing and bundled dependencies comply with applicable WordPress.org requirements.

## Contributing

Issues and pull requests are welcome when the repository is opened for community contributions.

When contributing, preserve the plugin's core principles:

**lightweight, secure, API-driven, modular, and provider-agnostic.**
