# PROJECT: SiteWatch — Professional WordPress Website Monitoring Platform

Build a complete, production-ready web application called **SiteWatch**.

SiteWatch is an internal website monitoring platform for a web development/digital agency that manages many client websites, primarily WordPress and WooCommerce websites.

The application must allow an administrator to add client websites using only their public URLs and continuously monitor their availability and health.

The primary objective is to detect problems **before the client reports them**, especially common WordPress failures such as:

- Website completely down
- WordPress Critical Error
- Error establishing a database connection
- HTTP 500 / 502 / 503 / 504
- Connection timeout
- DNS/connectivity failure
- SSL certificate problems
- Maintenance mode
- Redirect problems
- Very slow response
- Fatal PHP errors exposed on the page
- Other recognizable server/WordPress failures

This must be a **fully functional application**, not a static admin template or UI mockup.

---

# 1. CORE DEVELOPMENT REQUIREMENT

Build the application using:

- PHP 8.2+
- Core PHP — NO Laravel or other PHP framework
- MySQL / MariaDB
- PDO with prepared statements
- Composer
- Bootstrap 5.3
- Bootstrap Icons
- Vanilla JavaScript
- Fetch API / AJAX
- Chart.js

Use mature Composer packages where they provide reliable infrastructure.

Do not unnecessarily reinvent functionality already solved by trusted libraries.

At the same time, keep the actual website-monitoring intelligence custom to this application.

The finished application must work on:

- Local XAMPP
- Apache
- Standard Linux hosting
- cPanel shared/VPS hosting

Do NOT require:

- Laravel
- Node.js runtime
- React
- Vue
- Angular
- jQuery
- Redis
- Docker
- Supervisor

The production application should be capable of running using standard PHP + MySQL + cPanel Cron Jobs.

---

# 2. COMPOSER DEPENDENCIES

Use Composer and select current stable versions compatible with PHP 8.2+.

Use:

## HTTP Monitoring

`guzzlehttp/guzzle`

Use Guzzle as the primary HTTP client.

Use asynchronous/concurrent requests for monitoring multiple websites efficiently.

Use appropriate Guzzle Promise functionality such as:

`GuzzleHttp\Promise\Utils::settle()`

or another clean concurrency mechanism.

Requirements:

- asynchronous checks
- connection timeout
- request timeout
- redirect following
- redirect limits
- browser-like User-Agent
- SSL verification
- response body
- response headers
- HTTP status
- exception handling
- final URL
- redirect information where possible

One failed website must NEVER terminate the entire monitoring batch.

Do not launch hundreds of simultaneous connections.

Use configurable concurrency/batching.

Default:

15 websites per batch.

---

## Email

Use:

`phpmailer/phpmailer`

Use PHPMailer for SMTP email notifications.

Do NOT build a custom SMTP implementation.

---

## Logging

Use:

`monolog/monolog`

Use Monolog for application and monitoring logs.

Recommended log files/channels:

- app.log
- monitor.log
- cron.log
- notifications.log
- error.log

Never log passwords, SMTP passwords, API tokens or sensitive credentials.

---

## Environment Configuration

Use:

`vlucas/phpdotenv`

Create:

`.env`

and:

`.env.example`

Infrastructure configuration should include:

DB_HOST
DB_PORT
DB_NAME
DB_USER
DB_PASSWORD

APP_ENV
APP_DEBUG
APP_URL
APP_TIMEZONE

Never expose `.env` publicly.

---

## Scheduling

Use:

`dragonmantank/cron-expression`

where useful for scheduling logic.

However, the actual monitoring process must still be initiated by the operating system/cPanel cron.

---

## Database

Use native PDO.

Do NOT introduce a heavy ORM.

Create a clean repository/database layer.

---

## SSL

Use native PHP OpenSSL functionality for certificate inspection whenever possible.

Do not install unnecessary packages for functionality PHP already provides reliably.

---

# 3. APPLICATION ARCHITECTURE

Do not create procedural spaghetti PHP.

Use clean OOP and separation of responsibilities.

Suggested structure:

/sitewatch/

    /admin/
        dashboard.php
        websites.php
        website-add.php
        website-edit.php
        website-details.php
        incidents.php
        reports.php
        notifications.php
        settings.php
        profile.php

    /api/
        dashboard/
        websites/
        incidents/
        monitoring/
        settings/

    /app/
        /Core/
            Database.php
            Router.php
            Auth.php
            Session.php
            CSRF.php
            Validator.php

        /Monitoring/
            WebsiteMonitor.php
            MonitorManager.php
            ErrorDetector.php
            StatusClassifier.php
            SSLChecker.php
            IncidentManager.php
            UptimeCalculator.php
            MonitoringScheduler.php

        /Notifications/
            NotificationManager.php
            EmailNotifier.php
            TelegramNotifier.php

        /Repositories/
            WebsiteRepository.php
            CheckRepository.php
            IncidentRepository.php
            SettingsRepository.php
            UserRepository.php

        /Services/
            DashboardService.php
            ReportService.php
            ActivityService.php

    /config/
        app.php
        database.php

    /cron/
        monitor.php
        cleanup.php
        ssl-check.php

    /includes/
        header.php
        sidebar.php
        footer.php

    /assets/
        /css/
            app.css
        /js/
            app.js
            dashboard.js
            websites.js
        /images/

    /storage/
        /logs/
        /cache/
        /locks/

    /database/
        schema.sql

    login.php
    logout.php
    install.php
    index.php
    composer.json
    .env.example
    .htaccess
    README.md

You may improve this architecture if there is a technically cleaner solution.

Do not unnecessarily over-engineer it.

---

# 4. UI / UX DIRECTION

The application must have a **premium modern SaaS monitoring dashboard**, not the appearance of a generic Bootstrap admin template.

Take visual inspiration from the design quality and cleanliness of products such as:

- Better Uptime
- Linear
- Vercel
- Stripe Dashboard
- modern infrastructure monitoring tools

Do NOT directly clone any product.

Create an original interface inspired by these design principles.

Design characteristics:

- clean
- premium
- minimal
- professional
- spacious
- highly readable
- information-dense without feeling cluttered

Use approximately:

Main background:
#F8FAFC

Cards:
#FFFFFF

Primary:
#6C5CE7

Dark:
#111827

Text:
#0F172A

Muted:
#64748B

Border:
#E5E7EB

Success:
#16A34A

Warning:
#F59E0B

Danger:
#DC2626

Info:
#2563EB

Use:

- 12–16px card radius
- subtle shadows
- thin borders
- good whitespace
- clear hierarchy
- polished hover states
- skeleton/loading states
- tooltips where useful
- professional status indicators

Avoid:

- excessive gradients
- excessive shadows
- giant cards
- unnecessary animations
- overly colorful UI
- generic admin-template appearance

---

# 5. RESPONSIVE LAYOUT

Support:

- 1920px desktop
- 1440px desktop
- laptops
- tablets
- mobile

Desktop:

Fixed/collapsible sidebar.

Mobile:

Off-canvas navigation.

Tables should intelligently adapt or become horizontally scrollable.

Important monitoring information must remain easy to read on mobile.

---

# 6. DARK MODE

Create professionally designed:

Light Mode

and

Dark Mode.

Add theme toggle.

Store preference in localStorage.

Do not simply invert colors.

---

# 7. SIDEBAR

Navigation:

Dashboard

Monitoring
- Websites
- Add Website
- Incidents
- Response Times

Reports
- Uptime Reports
- Performance

System
- Notifications
- Monitoring Settings
- General Settings

Account
- Profile
- Logout

At the bottom display monitoring engine health:

Monitoring Engine
● Running

or:

Monitoring Engine
● Problem Detected

Include logged-in admin information.

---

# 8. AUTHENTICATION

Build secure admin authentication.

Features:

- Login
- Logout
- Remember Me if securely implemented
- Session handling
- Password hashing
- CSRF protection
- Login rate limiting
- Session regeneration
- Authentication middleware/checks

Use:

`password_hash()`

and:

`password_verify()`

Never store plaintext passwords.

---

# 9. LOGIN UI

Create a premium login screen.

Brand:

SiteWatch

Heading:

Welcome back

Text:

Sign in to your website monitoring dashboard.

Fields:

Email
Password

Options:

Remember Me

Button:

Sign In

Keep it minimal and polished.

---

# 10. DASHBOARD

Header:

Website Monitoring

Subtitle:

Monitor the uptime, performance and health of all your client websites.

Primary CTA:

+ Add Website

Display KPI cards:

- Total Websites
- Online
- Down
- Warnings
- Average Response Time
- Open Incidents

Example:

Total Websites
68

Online
63

Down
3

Warnings
2

Average Response
1.2s

Open Incidents
3

Make Down/Open Incident cards visually noticeable without making the interface aggressive.

---

# 11. DASHBOARD HEALTH OVERVIEW

Create an overall health section.

Example:

63 Healthy
2 Warning
3 Down

Display compact visualization.

Also show:

Overall Uptime — 99.97%

Average Response — 1.24 sec

Incidents This Month — 7

---

# 12. DASHBOARD CHARTS

Use Chart.js.

Useful charts:

- Response Time Trend
- Uptime Trend
- Incidents Last 30 Days
- Website Status Distribution

Do NOT overload dashboard with charts.

Charts must be responsive and visually clean.

---

# 13. MAIN WEBSITE TABLE

Create a professional monitoring table.

Columns:

Website
Client
Status
Response
HTTP
SSL
Uptime
Last Checked
Actions

Website row example:

[Favicon]

Northern Star Press
northernstarpress.co.uk

Client:
Northern Star Press

Status:
ONLINE

Response:
842 ms

HTTP:
200

SSL:
Valid · 73 days

Uptime:
99.98%

Last Checked:
36 sec ago

Actions menu:

- View Details
- Check Now
- Edit
- Pause
- Resume
- Delete

Opening the domain should launch the website in a new tab.

---

# 14. STATUS SYSTEM

Support clear statuses:

ONLINE

DOWN

SUSPECTED DOWN

CRITICAL ERROR

DATABASE ERROR

HTTP 500

HTTP 502

HTTP 503

HTTP 504

TIMEOUT

DNS ERROR

SSL ERROR

SSL WARNING

REDIRECT ERROR

MAINTENANCE

SLOW

WARNING

PAUSED

Use consistent badge styles.

---

# 15. STATUS PRIORITY

Use this approximate severity priority:

1. Connection/DNS failure
2. Timeout
3. HTTP 5xx
4. WordPress Critical Error
5. Database Error
6. SSL Error
7. Redirect Error
8. Maintenance
9. Critical Performance
10. Slow
11. Warning
12. Online
13. Paused

However, design the classifier carefully so the most useful root cause is displayed.

Example:

If HTTP 500 occurs and the response body clearly contains:

"There has been a critical error on this website"

display:

WORDPRESS CRITICAL ERROR

rather than only:

HTTP 500

Store the HTTP 500 as supporting diagnostic information.

---

# 16. SEARCH / FILTER / SORT

Instant search by:

- website
- domain
- client

Filters:

- All
- Online
- Down
- Critical
- Warning
- Slow
- SSL Expiring
- Paused

Sorting:

- Status
- Response Time
- Uptime
- Last Checked
- Client
- Newest
- Oldest

Remember useful filter state where appropriate.

---

# 17. ADD WEBSITE

Fields:

Website Name

Client Name

Website URL

Website Type:

- WordPress
- WooCommerce
- Other

Monitoring Interval:

- 1 minute
- 2 minutes
- 5 minutes
- 10 minutes
- 15 minutes
- 30 minutes

Monitoring toggle:

Enabled

Checks:

- HTTP Status
- WordPress Errors
- Response Time
- SSL Certificate
- Redirects
- Maintenance Mode
- Database Errors
- Exposed Fatal Errors

Alerts:

- Website Down
- Critical Error
- Slow Website
- SSL Expiry
- Recovery

Validate and normalize URLs.

Example:

example.com

becomes:

https://example.com

Prevent duplicate websites where appropriate.

---

# 18. WEBSITE MONITORING ENGINE

This is the most important part of the application.

Create a reliable WebsiteMonitor service.

For each website collect:

- HTTP status
- response time
- response body
- headers
- final URL
- redirect count
- connection errors
- timeout errors
- SSL result
- timestamp

Configure:

- realistic browser User-Agent
- connect timeout
- total timeout
- redirect limit
- SSL verification

Never disable SSL verification globally just to avoid errors.

Instead, detect and report SSL problems.

---

# 19. CONCURRENT CHECKING

Use Guzzle asynchronous/concurrent requests.

Do NOT sequentially wait for hundreds of websites.

Default batch/concurrency:

15

Make configurable.

Example:

100 websites

should be processed in controlled batches instead of creating 100 simultaneous uncontrolled requests.

The monitoring process must remain stable even when many websites are offline.

---

# 20. WORDPRESS ERROR DETECTOR

Create a dedicated ErrorDetector.

Detect common WordPress/public PHP errors such as:

"There has been a critical error on this website"

"Error establishing a database connection"

"Briefly unavailable for scheduled maintenance"

"Internal Server Error"

"Fatal error"

"Parse error"

"Uncaught Error"

"Allowed memory size"

"Maximum execution time"

"Call to undefined function"

"Service Unavailable"

Do not naively search for generic words such as "Warning" or "Error" and automatically classify the website as broken.

This could create false positives from normal page content.

Use:

- specific signatures
- regex where appropriate
- HTTP status context
- response structure
- confidence/severity rules

Error detection should be conservative and reliable.

---

# 21. WORDPRESS CRITICAL ERROR

A website can sometimes return HTTP 200 while showing a WordPress failure page.

Therefore:

HTTP 200 does NOT automatically mean the website is healthy.

If the response contains a recognized WordPress critical-error signature:

Status:

CRITICAL ERROR

even if:

HTTP 200

Store both pieces of information.

---

# 22. RESPONSE TIME

Measure total response time.

Default classification:

< 2 seconds
Healthy

2–5 seconds
Moderate

5–10 seconds
Slow

> 10 seconds
Critical Performance

Allow thresholds to be changed from Settings.

Do not classify a site as slow when the request actually timed out; timeout should take priority.

---

# 23. FALSE POSITIVE PROTECTION

A single failed request must NOT immediately create a downtime incident.

Implement consecutive failure confirmation.

Default:

Failure #1
→ Suspected Down

Retry/check again.

Failure #2
→ still suspected

Failure #3
→ Confirmed Down
→ Create Incident
→ Send Alert

Make threshold configurable.

Default failure confirmation:

3

For recovery:

Success #1
→ Pending Recovery

Success #2
→ Confirmed Recovery
→ Resolve Incident
→ Send Recovery Alert

Default recovery confirmation:

2

Store consecutive failure/success counters.

---

# 24. IMPORTANT MONITORING BEHAVIOR

Do not send alerts repeatedly every time cron runs.

Example:

Website goes down at 1:00 PM.

Send ONE downtime alert.

Checks at:

1:01
1:02
1:03
1:04

must not send duplicate downtime alerts.

When site recovers:

Send ONE recovery notification.

This incident lifecycle must be implemented correctly.

---

# 25. CRON MONITORING

Create:

`cron/monitor.php`

The recommended server cron can execute every minute:

`* * * * *`

The application itself determines which websites are due.

Example:

Site A:
1 minute

Site B:
2 minutes

Site C:
5 minutes

Site D:
15 minutes

At each cron run, select only due websites.

Do not check every website every minute if its configured interval is longer.

---

# 26. CRON OVERLAP PROTECTION

Prevent multiple monitoring processes from overlapping.

Use a robust lock.

For example:

`storage/locks/monitor.lock`

Prefer atomic locking such as `flock()`.

If another valid monitor process is already running:

exit safely.

Handle stale locks correctly.

Always release lock when process finishes.

---

# 27. MONITOR ENGINE HEALTH

Record heartbeat information.

Dashboard should display:

Monitoring Engine
Running

Last Run:
45 seconds ago

If cron has not executed within expected threshold, show:

Monitoring Engine
Not Running

Last Run:
8 minutes ago

This is extremely important because otherwise the monitoring system itself could silently stop monitoring.

---

# 28. INCIDENT SYSTEM

Create incidents automatically for confirmed failures.

Incident fields should include:

- Website
- Client
- Incident Type
- Title
- Error Message
- HTTP Status
- Started At
- Resolved At
- Duration
- Current Status
- Supporting diagnostics

Statuses:

OPEN
RESOLVED

Incident types:

DOWNTIME
WORDPRESS_CRITICAL
DATABASE_ERROR
HTTP_ERROR
TIMEOUT
DNS_ERROR
SSL_ERROR
REDIRECT_ERROR
PERFORMANCE

---

# 29. INCIDENT PAGE

Create:

Open Incidents

Resolved Incidents

Search/filter by:

- Website
- Client
- Type
- Status
- Date Range

Example:

CRITICAL ERROR

Northern Star Press

Started
14 Sep 2026 · 1:22 PM

Resolved
14 Sep 2026 · 1:31 PM

Duration
9 minutes

HTTP
500

Reason
WordPress Critical Error

---

# 30. WEBSITE DETAIL PAGE

Create a high-quality website health dashboard.

Header:

[Favicon]

Northern Star Press

northernstarpress.co.uk

ONLINE

Actions:

Check Now
Edit
Pause Monitoring
Open Website

Stats:

Current Status

24h Uptime

7d Uptime

30d Uptime

Average Response

Incidents This Month

SSL Status

---

# 31. RESPONSE-TIME CHART

Display historical response time.

Ranges:

24 Hours
7 Days
30 Days
90 Days

Do not fetch huge datasets unnecessarily.

Aggregate older data where appropriate.

---

# 32. UPTIME TIMELINE

Create a visual health timeline.

For example:

Green:
Online

Orange:
Warning/Slow

Red:
Down/Critical

Gray:
No Data / Paused

Tooltips should show:

Timestamp
Status
Response Time
HTTP Status

---

# 33. RECENT CHECKS

Display latest checks:

Time
Status
HTTP
Response
Error

Paginate historical data.

Do not load thousands of rows at once.

---

# 34. UPTIME CALCULATION

Calculate:

24-hour uptime

7-day uptime

30-day uptime

90-day uptime

Overall uptime

Use accurate monitoring/incident data.

Display:

99.98%

with appropriate precision.

Paused monitoring/no-data periods should not automatically be counted as downtime.

Document the calculation methodology.

---

# 35. SSL MONITORING

For HTTPS sites inspect certificate health.

Store:

- Valid / Invalid
- Expiry Date
- Days Remaining
- Issuer where useful

Alerts:

30 days
Warning

14 days
Warning

7 days
Critical Warning

Expired
Critical

Example UI:

SSL Valid
73 days remaining

or:

SSL Expiring
5 days remaining

SSL monitoring failure should not crash the main HTTP monitoring process.

---

# 36. EMAIL NOTIFICATIONS

Use PHPMailer.

Admin settings:

SMTP Host
SMTP Port
SMTP Username
SMTP Password
Encryption
From Email
From Name
Recipients

Provide:

Send Test Email

Never expose stored password back to the browser unnecessarily.

Protect credentials appropriately.

---

# 37. DOWN EMAIL

Example:

Subject:

Website Down — Northern Star Press

Include:

Website
Client
URL
Issue
HTTP Status
Response Time
Detected Time
Error/Diagnostic Information

CTA:

Open Website Details

Keep alert emails concise and professional.

---

# 38. RECOVERY EMAIL

Subject:

Website Recovered — Northern Star Press

Include:

Website
Recovered At
Total Downtime
Original Incident
Current HTTP Status
Current Response Time

---

# 39. TELEGRAM

Create optional Telegram notifications.

Settings:

Bot Token
Chat ID
Enable Telegram
Test Telegram Alert

Example:

🚨 Website Down

Northern Star Press
Critical WordPress Error

HTTP: 500
Detected: 1:22 PM

Recovery:

✅ Website Recovered

Northern Star Press
Downtime: 7 minutes

Telegram failure must not break the monitoring process.

---

# 40. NOTIFICATION MANAGER

Create a centralized NotificationManager.

Channels:

Email
Telegram

Design so additional channels can be added later.

Possible future channels:

Slack
Discord
WhatsApp

Do not implement those future channels now unless needed.

---

# 41. NOTIFICATION RULES

Global settings + per-site overrides.

Alert types:

- Down
- WordPress Critical Error
- Database Error
- HTTP 5xx
- Timeout
- SSL
- Slow
- Recovery

Prevent duplicate notifications.

Log notification attempts and results.

---

# 42. MANUAL CHECK NOW

Every website must have:

Check Now

When clicked:

- trigger an immediate check
- show loading state
- return actual result
- update UI using AJAX
- update status
- HTTP code
- response time
- detected issue
- last checked

Do NOT reload entire page.

Prevent repeated button clicking while request is running.

---

# 43. BULK ACTIONS

Support selection of multiple websites.

Actions:

- Check Now
- Pause
- Resume
- Change Interval
- Delete

Require confirmation before destructive bulk delete.

---

# 44. IMPORT

Support bulk website import.

Option 1:

Paste URLs:

example1.com
example2.com
example3.com

Option 2:

CSV upload.

CSV can contain:

Website Name
Client Name
URL
Type
Check Interval

Validate entries.

Report:

Imported
Duplicates
Invalid URLs
Failed

Do not silently discard errors.

---

# 45. EXPORT

Export CSV:

- Websites
- Incidents
- Uptime Reports

Ensure CSV output is safe and properly escaped.

---

# 46. FAVICONS

Attempt to display website favicon.

Favicon failure must never affect monitoring.

Use fallback globe/site icon when unavailable.

Cache favicon information where appropriate instead of repeatedly fetching it unnecessarily.

---

# 47. REPORTS

Create Uptime Reports.

Filters:

Website
Client
Date Range

Display:

Uptime
Downtime
Incident Count
Average Response Time
Slowest Response
Current SSL Status

Provide CSV export.

---

# 48. ACTIVITY LOG

Record meaningful administrative/system activity.

Examples:

Website Added

Website Edited

Monitoring Paused

Monitoring Resumed

Incident Opened

Incident Resolved

SSL Warning

Settings Changed

Do not fill activity log with every normal successful monitoring request.

---

# 49. DATABASE DESIGN

Create a complete SQL schema.

At minimum:

users

websites

website_checks

incidents

daily_stats

settings

notifications

activity_logs

monitor_heartbeats

---

# 50. WEBSITES TABLE

Include appropriate fields such as:

id

name

client_name

url

domain

type

status

previous_status

monitoring_enabled

check_interval

failure_count

success_count

last_http_status

last_response_time

last_error_type

last_error_message

last_checked_at

last_online_at

last_down_at

next_check_at

ssl_valid

ssl_expires_at

created_at

updated_at

Use correct data types.

Add indexes.

---

# 51. WEBSITE CHECKS

Suggested fields:

id

website_id

status

http_status

response_time

error_type

error_message

redirect_count

final_url

checked_at

Do not store entire website HTML responses in the database.

Only store useful diagnostics.

---

# 52. INCIDENTS

Suggested fields:

id

website_id

type

title

error_message

http_status

started_at

resolved_at

duration_seconds

status

created_at

updated_at

Add appropriate indexes.

---

# 53. DAILY AGGREGATION

Raw checks can become large.

Create daily_stats.

Store aggregated values such as:

website_id
date
total_checks
successful_checks
failed_checks
uptime_percentage
average_response_time
min_response_time
max_response_time
incident_count

This allows long-term reports without retaining every raw check forever.

---

# 54. DATA RETENTION

Settings:

Keep Detailed Checks:

7 days
30 days
60 days
90 days

Default:

30 days

Keep:

Incidents — indefinitely

Daily Stats — indefinitely

Activity Logs — configurable

Create:

`cron/cleanup.php`

Cleanup should use efficient batched deletes.

---

# 55. DATABASE PERFORMANCE

Add indexes for common queries such as:

website_id + checked_at

status

next_check_at

monitoring_enabled

incident status

started_at

Do not perform N+1 queries unnecessarily.

Use pagination.

Optimize dashboard queries.

---

# 56. SECURITY

Perform a serious security implementation.

Required:

- PDO prepared statements
- CSRF tokens
- output escaping
- secure sessions
- password hashing
- input validation
- URL validation
- authorization checks
- login rate limiting
- secure cookies where appropriate
- protection against SQL injection
- protection against stored/reflected XSS
- protection against CSRF
- safe file upload validation for CSV
- safe error handling
- production debug disabled

Never show PHP stack traces to normal users.

---

# 57. SSRF PROTECTION — VERY IMPORTANT

Because SiteWatch makes HTTP requests to administrator-provided URLs, implement protection against Server-Side Request Forgery.

By default, reject monitoring targets that resolve to:

- localhost
- 127.0.0.0/8
- ::1
- private IPv4 networks
- link-local networks
- metadata endpoints
- private/internal IPv6 ranges
- other non-public addresses

Resolve DNS and validate resulting IP addresses before making monitoring requests.

Consider DNS rebinding risk.

Revalidate redirect destinations.

Do not allow a public URL to redirect the monitor into a private/internal network.

Provide an explicit configuration option only if private-network monitoring is intentionally needed in a trusted environment.

This protection is mandatory.

---

# 58. FILE SECURITY

Protect:

.env
composer.json if appropriate
storage/
logs/
config/
database/
vendor internals where appropriate

Do not place sensitive logs inside publicly accessible locations unless access is blocked.

Prefer keeping sensitive application data outside the public document root when deployment allows it.

Create appropriate `.htaccess` rules for Apache/cPanel.

---

# 59. SETTINGS

General:

Application Name

Application URL

Timezone

Default Monitoring Interval

Default Failure Threshold

Default Recovery Threshold

Request Timeout

Connect Timeout

Slow Threshold

Critical Performance Threshold

Concurrency

Detailed Check Retention

---

# 60. TIMEZONE

Store database timestamps consistently, preferably UTC.

Display according to configured application timezone.

Default:

Asia/Karachi

Example:

14 Sep 2026
1:42 PM

---

# 61. AJAX / API

Use Fetch API.

Use JSON responses consistently.

Recommended structure:

{
    "success": true,
    "message": "Website checked successfully.",
    "data": {}
}

Errors:

{
    "success": false,
    "message": "Unable to check website.",
    "errors": {}
}

Use correct HTTP status codes.

Protect state-changing endpoints with authentication and CSRF.

---

# 62. TOASTS

Use polished toast notifications.

Examples:

Website added successfully.

Check completed.

Monitoring paused.

Website recovered.

Settings saved.

Avoid browser `alert()` for normal UI interactions.

---

# 63. AUTO REFRESH

Dashboard should update important monitoring data every approximately 30–60 seconds.

Use AJAX.

Do not reload entire page.

Update:

- KPI cards
- website status
- response times
- last checked
- incidents
- monitoring engine status

Avoid overlapping refresh requests.

Pause/reduce polling when browser tab is hidden where practical.

---

# 64. EMPTY STATES

Examples:

No Websites:

"No websites are being monitored yet."

CTA:

Add Your First Website

No Incidents:

"Everything looks healthy. No incidents detected."

No Search Results:

"No websites match these filters."

---

# 65. INSTALLATION WIZARD

Create:

install.php

Steps:

1. Requirements
2. Database
3. Database Tables
4. Admin Account
5. Application Settings
6. Complete

Check:

PHP 8.2+
PDO
PDO MySQL
cURL
OpenSSL
JSON
mbstring
Composer dependencies
Writable storage directories

After installation:

Create installation lock.

Prevent install.php from being reused unless deliberately unlocked.

---

# 66. README

Create professional README.md.

Include:

Requirements

Local XAMPP Installation

Composer Installation

Database Setup

Environment Configuration

cPanel Deployment

Cron Configuration

SMTP Setup

Telegram Setup

File Permissions

Security Recommendations

Troubleshooting

---

# 67. CPANEL DEPLOYMENT

The final project must include exact instructions for deployment.

Cover:

- uploading files
- document root considerations
- PHP version
- Composer/vendor deployment
- MySQL database creation
- database user
- .env configuration
- permissions
- cron configuration
- SMTP
- HTTPS
- first admin login

Example cron format:

`/usr/local/bin/php /home/USERNAME/path/to/sitewatch/cron/monitor.php`

Do not assume this exact PHP path exists.

Explain how to determine the correct PHP binary/path in cPanel.

---

# 68. MONITORING SCALE

Design initial application comfortably for approximately:

50–500 websites

depending on server resources and monitoring interval.

Do not claim unlimited scalability.

Use:

- controlled concurrency
- efficient queries
- scheduling
- aggregation
- retention policies
- pagination

Structure the application so a queue/worker architecture can be introduced later if monitoring volume grows significantly.

Do not require queues for the initial cPanel-compatible version.

---

# 69. FAILURE ISOLATION

One website failure must not affect another.

One notification failure must not affect monitoring.

One SSL-check failure must not stop HTTP monitoring.

One malformed website record must not crash cron.

Catch errors at appropriate boundaries.

Log them and continue processing safely.

---

# 70. MONITORING LOGIC MUST REMAIN CUSTOM

Libraries should provide infrastructure.

Do NOT outsource the actual business logic to random packages.

Custom application logic must handle:

- WordPress error recognition
- health classification
- incident lifecycle
- failure confirmation
- recovery confirmation
- alert deduplication
- uptime calculations
- status priority
- due-check scheduling
- monitoring heartbeat
- performance thresholds

Keep these components clean and independently testable.

---

# 71. TESTING

Before considering the project complete, test realistic scenarios.

Test:

Healthy HTTP 200 website

HTTP 404 homepage

HTTP 500

HTTP 502

HTTP 503

HTTP 504

Connection refused

DNS failure

Timeout

Redirect chain

Redirect loop

Invalid SSL

Expired SSL

WordPress Critical Error returning HTTP 500

WordPress Critical Error returning HTTP 200

Database connection error

Maintenance mode

Slow response

Recovery after downtime

Temporary one-check failure

Three consecutive failures

Notification deduplication

Paused website

Cron not running

Bulk import

Authentication

CSRF

Invalid URLs

SSRF/private IP rejection

Mobile responsiveness

Dark mode

---

# 72. DO NOT CREATE FAKE FUNCTIONALITY

This requirement is critical.

Do NOT create:

- buttons with no functionality
- fake charts
- fake status information
- hardcoded dashboard statistics
- placeholder AJAX responses
- dummy incidents presented as real data
- fake "Check Now"
- fake notification testing

All dashboard information must come from the database and actual monitoring results.

If a feature cannot be implemented immediately, clearly identify it instead of pretending it works.

---

# 73. DEVELOPMENT WORKFLOW

If an existing project directory is provided, inspect it first.

Do not overwrite useful existing code without understanding it.

Then work in this order:

PHASE 1
Project architecture and Composer

PHASE 2
Environment and database

PHASE 3
Authentication

PHASE 4
Main UI shell

PHASE 5
Website CRUD

PHASE 6
HTTP monitoring engine

PHASE 7
WordPress error detection

PHASE 8
Failure/recovery confirmation

PHASE 9
Incident management

PHASE 10
Cron scheduling

PHASE 11
SSL monitoring

PHASE 12
Email/Telegram notifications

PHASE 13
Dashboard analytics

PHASE 14
Website details

PHASE 15
Reports

PHASE 16
Import/export

PHASE 17
Settings

PHASE 18
Installer

PHASE 19
Security review

PHASE 20
Performance review

PHASE 21
Responsive/UI polish

PHASE 22
End-to-end testing

Do not stop after creating the UI.

Continue until the application functionality is implemented.

---

# 74. UI QUALITY REVIEW

Before finishing, review every screen for:

- spacing
- alignment
- typography
- mobile responsiveness
- empty states
- loading states
- button consistency
- form validation
- table readability
- dark mode
- status colors
- chart readability
- modal/dropdown behavior

The application should look like a professionally designed SaaS product, not a student PHP project.

---

# 75. CODE QUALITY REVIEW

Before finishing, inspect for:

- duplicated code
- SQL injection
- XSS
- CSRF
- SSRF
- insecure sessions
- exposed credentials
- incorrect paths
- broken includes
- PHP warnings
- deprecated PHP functionality
- N+1 queries
- cron overlap
- unhandled exceptions
- notification duplication
- incorrect uptime calculation
- timezone bugs

Fix discovered issues.

---

# 76. FINAL DELIVERABLE

At completion I expect a fully working application containing:

- Premium responsive dashboard
- Secure admin login
- Website CRUD
- Client website organization
- Real URL monitoring
- Guzzle concurrent checks
- WordPress Critical Error detection
- Database-error detection
- HTTP error monitoring
- Timeout/DNS monitoring
- Response-time monitoring
- SSL monitoring
- Retry/failure confirmation
- Recovery confirmation
- Incident lifecycle
- Email alerts
- Telegram alerts
- Notification deduplication
- Monitoring heartbeat
- Check Now
- Bulk actions
- Search/filter/sorting
- Uptime statistics
- Response-time analytics
- Website health pages
- Incident history
- Reports
- CSV import/export
- Activity logs
- Dark mode
- AJAX updates
- Data retention
- Installation wizard
- Composer configuration
- SQL schema
- .env.example
- .htaccess protection
- Cron scripts
- README
- cPanel deployment instructions

The result must be usable as an actual internal agency monitoring system.

---

# 77. FINAL INSTRUCTION TO CLAUDE CODE

Do not merely explain how to build SiteWatch.

**Actually build it.**

When you have filesystem/project access:

1. Inspect the current project directory.
2. Create or improve the architecture.
3. Install/configure the required Composer dependencies.
4. Create the database schema.
5. Implement the application phase-by-phase.
6. Run syntax checks and available tests throughout development.
7. Fix errors rather than leaving TODOs.
8. Verify routes, includes, AJAX endpoints and database queries.
9. Review security.
10. Review monitoring accuracy.
11. Review UI/responsiveness.
12. Provide final installation/deployment instructions.

If you encounter an implementation decision that is not explicitly specified here, choose the solution that is:

- secure
- maintainable
- lightweight
- reliable
- compatible with PHP 8.2+
- compatible with cPanel
- appropriate for an internal agency monitoring platform

Avoid unnecessary complexity.

Do not convert the project to Laravel or another framework.

Do not replace Core PHP with a JavaScript SPA.

Do not sacrifice monitoring reliability for visual effects.

**Priority order:**

1. Monitoring accuracy
2. Reliability
3. Security
4. Incident/notification correctness
5. Performance
6. Maintainability
7. User experience
8. Visual polish

The final result should feel like a real production monitoring product while remaining lightweight enough to self-host on standard PHP/cPanel infrastructure.