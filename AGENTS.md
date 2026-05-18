# AGENTS.md

## Cursor Cloud specific instructions

### Architecture

This is a traditional LAMP-stack PHP application (no frameworks, no package managers, no build tools, no test frameworks). Two dashboards share a single MariaDB database:

- **ZAP Dashboard** (`/workspace/zap/`) — SEO backlinks and keyword rankings
- **DMC Dashboard** (`/workspace/dmc/`) — AI content creation for europamaut.com

### Running the application

Services required (start in this order):

```bash
sudo service mariadb start
sudo service apache2 start
```

The app is served at `http://localhost/dashboards/` via an Apache Alias mapping `/dashboards` → `/workspace`.

- Landing page: `http://localhost/dashboards/`
- ZAP Dashboard: `http://localhost/dashboards/zap/`
- DMC Dashboard: `http://localhost/dashboards/dmc/`

### Database

- MariaDB, database: `zap_dashboard`, user: `zapdash` / `Zap@Dashboard2026!`
- The `backlinks` and `rankings` tables must be created manually (schema in the update script). DMC tables (`dmc_content_threads`, `dmc_content_thread_messages`, `dmc_system_prompt_versions`, `dmc_content_drafts`, `dmc_dashboard_states`) auto-create on first API access.

### Linting

No linter is configured. Use `php -l` for syntax checking:

```bash
find /workspace -name "*.php" -exec php -l {} \;
```

### Testing

No automated test framework exists. Manual testing is done via:
- API calls: `curl http://localhost/dashboards/zap/api/backlinks.php`
- Browser: navigate to the dashboards

### Key caveats

- All internal URL paths assume the `/dashboards/` prefix (hardcoded in JS and PHP). The Apache config uses `Alias /dashboards /workspace`.
- DMC content generation workflows (`workflow_content.php`, `workflow_revision.php`, `workflow_questions.php`) require an OpenAI API key. Without it, the content form submission will fail.
- The DataForSEO sync and Google Docs publishing are optional features requiring external API credentials.
- Apache config lives at `/etc/apache2/sites-available/dashboards.conf`.
