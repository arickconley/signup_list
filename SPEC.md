# Signup Sheets v1 Specification

## 1. Product Summary

Signup Sheets is a mobile-first web application for creating and sharing Signup Sheets for needs such as snack signups. Account holders create sheets; participants claim available options through unguessable UUID links.

Owners must have an eligible verified account. Participants may sign up without an account on Open sheets or use a passwordless account for persistent access and editing.

## 2. Goals

- Make creating and sharing a signup sheet fast.
- Keep low-stakes sheets usable without participant registration.
- Let owners require verified participants when stronger limits matter.
- Make option capacity and availability clear.
- Give owners control without allowing them to impersonate participants.
- Protect participant contact information through explicit consent.
- Keep deployment simple with Laravel, SQLite, and one persistent application instance.
- Provide an accessible, polished mobile experience.

## 2.1 Frontend Architecture

- Livewire 4 provides server-driven reactivity.
- Project-owned Blade components and Tailwind CSS 4 provide the UI layer.
- Small client-side interactions use the Alpine.js runtime included with Livewire.
- v1 does not use Flux or another general-purpose UI component library. This keeps licensing clear, dependencies small, and product styling under project control.
- Shared controls must meet WCAG 2.1 AA, including visible focus, keyboard operation, accessible names, error association, touch targets, reduced-motion support, and light/dark contrast.

## 3. v1 Non-Goals

- Multiple owners or collaborators on one sheet.
- Waitlists.
- Sheet passwords or access codes.
- Search, discovery, or indexing of sheets.
- Daily or weekly owner email digests.
- Social login.
- CAPTCHA unless observed abuse justifies it later.
- CSV or generated-PDF export.
- Restoring archived sheets.
- Permanently deleting an individual sheet. Account deletion is the sole exception and deletes all sheets owned by that account.
- Multi-instance or serverless deployment.

## 4. Roles and Identity

### 4.1 Account

An Account is one identity that may participate in sheets and may become an Owner. There are no separate owner and participant account types.

- Accounts start passwordless.
- Access uses a one-time code and magic link sent by email.
- Verified accounts may add an optional password and one or more passkeys.
- Accounts using a password may enable optional TOTP two-factor authentication with recovery codes.
- Email addresses are normalized and globally unique.
- An account profile stores name, optional phone, email, and timezone.
- Profile name and phone are defaults for future signups. Existing signup snapshots never change when the profile changes.
- An account dashboard lists all non-archived sheets the account owns or has joined.
- Social login is a v2 roadmap item.

### 4.2 Owner Eligibility

An account may create sheets only when:

- Its email is verified.
- Its email domain is not in the repository-maintained disposable-email blocklist.

Disposable-email blocking does not apply to participants.

### 4.3 Unregistered Participant

An Unregistered Participant supplies a required name and optional phone, but no email.

- No account is created.
- The participant may complete an initial signup on an Open sheet.
- The participant cannot later edit or cancel that signup.
- Limits are enforceable per submission only and may be bypassed by submitting again. The UI must not claim stronger enforcement.
- The owner may remove the participant's claims or entire signup.

### 4.4 Participant Supplying Email

Supplying an email creates or locates an Account and starts verification.

- The signup completes immediately; email verification is not required to reserve capacity on an Open sheet.
- Until verification, the signup has a Pending Account Association and cannot be edited by the participant.
- The participant receives confirmation details, a one-time code, and a magic login link in one email.
- After verification, the signup attaches to the Account and becomes editable while the sheet is open.
- If the email already has a signup on that sheet, no second signup is accepted. A neutral message tells the participant to check email, and a login link is sent.
- The response must not reveal whether an email already has an account.

### 4.5 Owner Participation

Owners may participate in their own sheets using the same account and participant workflow.

## 5. Signup Sheet

### 5.1 Fields

Each sheet has:

- Required title.
- Optional description.
- Optional event date and time.
- Optional location.
- Required signup deadline.
- Owner timezone snapshot used to interpret its dates and times.
- Participation Policy: Open or Verified.
- Positive maximum number of options each signup may claim. It cannot exceed the sheet's current option count.
- Name, email, and phone visibility settings.
- Ordered list of Options.
- Random UUIDv4 public identifier.

New sheets default to:

- Draft status.
- Open Participation.
- Deadline 14 days after creation at 11:59 PM in the owner's timezone.
- Participant name, email, and phone hidden from other viewers.

Drafts may be incomplete. Publishing requires at least one Option and a valid selection maximum.

Owners may edit sheet content and settings after publication. Changes apply immediately, subject to privacy consent, deadline, capacity, and destructive-action rules in this specification.

### 5.2 Participation Policies

#### Open Participation

- Default policy.
- Name required.
- Email and phone optional.
- Participants may use an existing Account, create one by supplying email, or remain unregistered.
- Selection maximum is strict per Signup but is a soft per-person limit for unregistered participants.

#### Verified Participation

- A verified Account is required before signup.
- Name is required; account email is required; phone remains optional.
- Exactly one Signup exists per Account per sheet.
- The selection maximum is enforceable per participant.

### 5.3 Options

Each Option has:

- Required name.
- Optional short description.
- Positive whole-number capacity; zero is invalid.
- Owner-controlled display position.

Rules:

- One Option Claim consumes exactly one capacity unit.
- Owners may add, edit, and reorder options after publication.
- When deleting an option would leave the selection maximum greater than the remaining option count, lower the maximum to the remaining count.
- A full option accepts no more claims. There is no waitlist.
- Owners may reduce capacity below current claims. Existing claims remain, the option is marked over capacity, and it accepts no new claims.
- Increasing capacity immediately makes the new capacity available.
- Deleting an option with claims requires explicit confirmation, removes its claims, and emails affected participants who supplied email.
- Capacity enforcement must be atomic so concurrent submissions cannot exceed capacity.

If an owner lowers the sheet's selection maximum below an existing Signup's claim count, preserve its existing claims and mark the Signup over limit. It accepts no added claims; an Account participant may remove claims until compliant.

### 5.4 Lifecycle

#### Draft

- Visible only to the owner.
- Editable and previewable.
- Does not accept signups.

#### Published and Open

- Available through its UUID link.
- Accepts new signups and permitted participant edits.

#### Published and Closed

- Reached manually or when the deadline passes.
- Remains publicly viewable through its UUID link.
- Does not accept new signups or participant edits.
- The owner may reopen it by setting a future deadline.

#### Archived

- Owner-only and visible in the owner's archived list.
- Public URL returns a generic unavailable response with no sheet details.
- Cannot be restored.
- Cannot be individually deleted in v1.
- Is permanently deleted if its owner deletes their Account.

### 5.5 Duplication

Owners may duplicate any sheet into a new Draft.

Copy:

- Title and description.
- Event details and location.
- Options, descriptions, capacities, and order.
- Participation Policy.
- Selection maximum.
- Visibility defaults.

Do not copy:

- Signups or Option Claims.
- Participant consent.
- Original UUID.
- Original deadline.

The duplicate gets a new UUID and the standard 14-day default deadline.

## 6. Signup Workflow

### 6.1 Public Sheet Page

The page displays:

- Title, description, event details, location, and deadline.
- Open or closed state.
- Every Option in owner-defined order.
- Option name, description, total capacity, claimed count, and remaining count.
- Public participant information allowed by both sheet settings and participant consent.
- A clear signup action while open.

Full and over-capacity options are visible but unavailable for new claims.

### 6.2 Creating a Signup

While the sheet is open, a participant:

1. Selects between one and the sheet's maximum number of available Options.
2. Supplies required name.
3. Supplies email when desired or required by Verified Participation.
4. Optionally supplies phone.
5. Reviews explicit per-field visibility controls when the sheet permits public information.
6. Submits once.

The server must validate all capacities and the selection maximum again inside the write transaction. On a race, the response identifies options that became unavailable and lets the participant choose again.

### 6.3 Signup Data

One Signup groups:

- Participant/account association when verified.
- Pending email association when supplied but unverified.
- Name snapshot.
- Email snapshot when supplied.
- Phone snapshot when supplied.
- Visibility consent per field.
- One or more Option Claims.

An email address may appear at most once per sheet. Null email values are not unique, allowing multiple unregistered signups.

### 6.4 Participant Editing

A verified Account participant may edit while the sheet is open:

- Claimed Options, within capacity and selection maximum.
- Signup name snapshot.
- Signup phone snapshot.
- Visibility consent.

They may also cancel the entire Signup. Cancellation releases all capacity.

Unregistered and pending-verification participants cannot edit or cancel. There is no owner setting to disable editing for verified Account participants.

### 6.5 Owner Controls

Owners can view signups grouped by Participant or grouped by Option, switched by a toggle.

Owners may:

- Remove one Option Claim.
- Remove an entire Signup.

Owners may not:

- Add an Option Claim for a participant.
- Change participant name, email, or phone.
- Change participant visibility consent.

When an owner removes a claim or signup belonging to an emailed participant, send an email containing:

- Sheet title and link.
- Before and after selections.
- Clear statement that the owner made the change.

## 7. Privacy and Visibility

### 7.1 Owner Settings

Name, email, and phone visibility are separate. Each defaults to owner-only.

- An owner setting determines whether a field is eligible for public display.
- Participant consent is also required for public display.
- Participant refusal always wins.
- Owners always see details supplied to their sheet.

### 7.2 Public Rendering

- If names are owner-only, show only aggregate counts.
- If names are public and the participant consents, show the signup name snapshot.
- If names are public but the participant withholds full-name consent, show initials.
- Email and phone display only when both owner setting and participant consent allow it.
- Never expose account profile values not copied into that Signup.

### 7.3 Visibility Changes

- Owners may make any field more private at any time.
- Making a field eligible for public display applies automatically only to future submissions.
- Existing Account participants may explicitly consent during a later edit.
- Existing Unregistered Participant data remains private permanently because those participants cannot return to consent.

### 7.4 Search and Discovery

- No sheet is included in application search or public listings.
- All public and authenticated sheet pages send `noindex, nofollow` directives.
- Public routes use only random UUIDv4 identifiers, never sequential database IDs.
- Archived and unknown UUIDs return the same generic unavailable response.

## 8. Dashboards

### 8.1 Account Dashboard

Show:

- Owned sheets separated by Draft, Open, Closed, and Archived.
- Non-archived sheets the Account joined.
- Pending email associations after they are verified and claimed.
- Actions to create, view, edit, close/reopen, archive, duplicate, and print owned sheets as applicable.

### 8.2 Owner Signup View

- Toggle grouping by Participant or Option.
- Show current totals, remaining capacity, and over-capacity warnings.
- Show all submitted participant details to the owner.
- Provide claim and signup removal actions with confirmation.

### 8.3 Print View

Provide an owner-only HTML page optimized for browser printing and Save as PDF.

- Support grouping by Participant or Option.
- Allow email and phone columns to be shown or hidden.
- Include sheet heading, event details, deadline, and option totals.
- Hide navigation, buttons, and other interactive chrome in print CSS.
- Do not create or store PDF files.

## 9. Authentication and Sessions

- Every Account begins with passwordless email access, which remains available after other sign-in methods are added.
- Passwordless login sends both a short one-time code and signed magic link.
- Tokens are random, single-use, short-lived, and stored only as hashes.
- A verified Account may create, replace, or remove its password after fresh authentication.
- Passwords must use Laravel's configured secure hash and the application's password-strength rules.
- Password reset uses a time-limited, single-use email link without disclosing whether the Account exists.
- A verified Account may register, name, view, and revoke multiple WebAuthn passkeys after fresh authentication.
- Passkey ceremonies must validate the configured relying party, origin, challenge, and Account association.
- Password users may optionally enable TOTP two-factor authentication and receive one-time recovery codes.
- Enabling, disabling, or regenerating two-factor credentials requires fresh authentication.
- Successful verification establishes a server-side session using a Secure, HttpOnly, SameSite cookie in production.
- Never store a raw account ID as an authentication cookie.
- Code entry, login email requests, and resend actions are rate-limited.
- Responses do not disclose whether an account exists.
- Owner-only destructive actions require normal CSRF protection.
- Account deletion requires fresh email verification even when an active session exists.

Initial defaults, configurable in application settings:

- Magic link/code lifetime: 15 minutes.
- Resend cooldown: 60 seconds.
- Limit verification attempts and email sends per address and IP.

## 10. Email

Use Laravel's provider-agnostic Mail abstraction. Production delivery is configured through environment variables; local development uses Mailpit or the log driver.

### v1 Emails

- Signup confirmation plus login code/link when email is supplied.
- New verification/login code/link on request.
- Password reset and security-credential change notifications.
- Owner removal of a claim or signup.
- Owner deletion of an Option with claims.

### v1 Exclusions

- No immediate owner email for participant activity.
- No owner digests. Daily/weekly account-wide digests are planned for v2.
- No email when an owner account deletion removes its sheets.

All email sending uses queued jobs. The web request must not wait for delivery.

## 11. Account Deletion

Account deletion requires:

1. Fresh email verification.
2. A summary count of all owned Draft, Open, Closed, and Archived sheets.
3. Explicit irreversible confirmation.

Deletion then:

- Permanently deletes every owned Sheet, Option, Signup, Option Claim, and related consent/activity record.
- Makes every owned public UUID unavailable.
- Removes the Account profile, authentication credentials/tokens, and sessions.
- Keeps the deleted Account's Option Claims on sheets owned by others so capacity and organizer records remain stable.
- Detaches those retained Signups from the Account.
- Replaces retained name, email, and phone snapshots with `Deleted participant` and null contact fields.
- Sends no participant notifications about deleted owned sheets.

## 12. Owner Account Abuse Controls

- Maintain a normalized local blocklist of known disposable-email domains in the repository.
- Check the blocklist when an Account attempts to create its first sheet and whenever owner eligibility is reevaluated.
- Existing participant accounts using blocked domains may continue participating but cannot own sheets.
- Normalize domains case-insensitively and handle subdomains consistently.
- Rate-limit account creation, login email sending, sheet publication, and signup submission.
- Use CSRF protection, server-side validation, output escaping, and a hidden honeypot field on unauthenticated forms.
- Do not include CAPTCHA in v1. Monitor removal volume, signup rate-limit events, and delivery failures before adding friction.

## 13. Technical Architecture

### 13.1 Stack

- Laravel.
- Server-rendered Blade templates.
- Livewire for reactive forms, capacity updates, ordering, and dashboard interactions.
- Tailwind CSS.
- SQLite on a persistent disk.
- Database-backed queue for email and cleanup jobs.
- Laravel scheduler for cleanup and maintenance.

### 13.2 Deployment Constraint

Run one application instance with one persistent SQLite database file. Multi-instance and serverless deployment are unsupported in v1. Document this prominently in deployment instructions.

### 13.3 Core Data Model

Suggested entities:

#### `accounts`

- Internal primary key.
- Normalized unique email.
- Email verification timestamp.
- Nullable password hash.
- Nullable encrypted TOTP secret and recovery codes.
- Name.
- Optional phone.
- Timezone.
- Timestamps.

#### `sheets`

- Owner account foreign key.
- Unique UUIDv4 public identifier.
- Title and optional description.
- Optional event timestamp and location.
- Deadline timestamp and timezone snapshot.
- Lifecycle state and manual-close flag.
- Participation Policy.
- Selection maximum.
- Per-field visibility settings.
- Timestamps and archive timestamp.

#### `options`

- Sheet foreign key.
- Name and optional description.
- Positive capacity with database check constraint.
- Display position.
- Timestamps.

#### `signups`

- Sheet foreign key.
- Nullable verified account foreign key.
- Nullable normalized pending/snapshot email.
- Name and optional phone snapshots.
- Per-field consent flags.
- Verification/association state.
- Timestamps.

Constraints:

- Unique `(sheet_id, account_id)` when account ID is non-null.
- Unique `(sheet_id, normalized_email)` when email is non-null.
- Multiple null emails allowed.

#### `option_claims`

- Signup foreign key.
- Option foreign key.
- Timestamps.
- Unique `(signup_id, option_id)`.

#### Authentication and activity support

- Hashed, expiring one-time login tokens/codes.
- WebAuthn passkeys associated with an Account, including credential identifier, public key, usage counter, and display name.
- Queue jobs and failed-job records.
- Minimal owner-action records needed to create before/after notification emails and diagnose abuse.

### 13.4 Capacity Transaction

Creating or editing claims must run in a database transaction:

1. Re-read the Sheet and selected Options.
2. Confirm the Sheet is open.
3. Confirm selection count is within maximum.
4. Confirm each option exists on the Sheet.
5. Count current claims for each option.
6. Reject full or over-capacity options.
7. Insert/delete claims and commit atomically.

SQLite writes must use an immediate write transaction, configured busy timeout, and bounded retry for lock contention. Keep transactions short and never send email inside the transaction; dispatch jobs after commit.

### 13.5 Time Handling

- Store timestamps in UTC.
- Store the Sheet's timezone snapshot for display and deadline interpretation.
- Detect a new Account's timezone from the browser, then allow profile changes.
- Deadline closure is authoritative on the server; the UI countdown is informational.

### 13.6 Cleanup

Scheduled jobs remove:

- Expired login tokens and codes.
- Expired framework sessions.
- Old failed/pending verification artifacts under a documented retention policy.

Cleanup must not delete completed Signups merely because an email was never verified.

## 14. UX and Accessibility

- Mobile-first design is the priority.
- Meet WCAG 2.1 AA.
- Every flow must be keyboard usable.
- Use semantic headings, fieldsets, labels, validation summaries, and live-region announcements for reactive changes.
- Do not rely on color alone for full, closed, over-capacity, error, or success states.
- Provide visible focus styles and sufficient target sizes.
- Confirm destructive owner actions and account deletion clearly.
- Preserve entered form values after recoverable validation or capacity-race failures.
- Clearly distinguish total capacity, claimed, and remaining counts.
- State when Open Participation limits are soft because email is optional.
- Initials and hidden contact fields need accessible labels that do not expose private values.

## 15. Primary Acceptance Scenarios

### Owner creates and publishes

1. A verified eligible Account creates a Draft.
2. Owner adds ordered Options with positive capacities.
3. Owner sets selection maximum and optional event details.
4. Owner publishes.
5. UUID link accepts signups and is marked noindex.

### Unregistered Open signup

1. Participant opens an Open sheet.
2. Selects available Options within the maximum.
3. Enters name, no email, optional phone, and visibility choices.
4. Signup completes and capacity decreases by one per claim.
5. Participant cannot edit later; owner may remove claims.

### Email-backed Open signup

1. Participant submits name, email, optional phone, and claims.
2. Signup completes immediately.
3. Confirmation email includes details, code, and magic link.
4. Before verification, participant cannot edit.
5. After verification, Signup attaches to Account and appears on dashboard.
6. Participant edits while open; capacity updates atomically.

### Verified sheet signup

1. Unauthenticated visitor is asked to verify email.
2. Verified Account signs up once with up to the maximum claims.
3. A repeat attempt routes through login to the existing Signup.

### Concurrent final claim

1. Two participants attempt the final unit concurrently.
2. Exactly one transaction succeeds.
3. The other participant sees that the option became unavailable without losing other form input.

### Owner reduces capacity

1. Option has five claims.
2. Owner lowers capacity to three.
3. All five claims remain.
4. Option is visibly over capacity and accepts no new claims.

### Owner removes participant claim

1. Owner confirms removal of one claim.
2. Capacity is released.
3. Participant identity remains unchanged.
4. If email exists, participant receives before/after selections and sheet link.

### Privacy broadening

1. A field was owner-only when existing participants signed up.
2. Owner makes it eligible for public display.
3. Existing data remains private.
4. Account participants may consent on edit.
5. Unregistered participants' existing data remains private permanently.

### Account deletion

1. Account re-verifies email and sees owned-sheet counts.
2. Account explicitly confirms deletion.
3. Owned sheets and UUID pages disappear permanently.
4. Claims on others' sheets remain but show `Deleted participant` with no contact details.

## 16. Operational Decisions Required Before Production

- Production email provider and sender-domain authentication.
- SQLite backup destination, encryption, schedule, and retention.
- Application URL and HTTPS termination.
- Queue worker and scheduler process supervision.
- Log retention and alerting for failed email jobs, database lock errors, and queue failures.
- Source and update cadence for the disposable-email domain blocklist.

## 17. v2 Candidates

- Social login.
- Account-wide daily or weekly owner digests.
- Collaboration and ownership transfer.
- Abuse-triggered CAPTCHA or stronger participant verification.
- Waitlists.
- Sheet access codes.
- Additional exports.

## 18. License and Source Availability

- The application is distributed under GNU Affero General Public License v3.0 or later (`AGPL-3.0-or-later`).
- Commercial use is permitted, but modified distributions and modified network services must provide their corresponding source under the AGPL to applicable recipients or network users.
- The license does not require changes to be contributed to this upstream repository.
- Every interactive deployment must provide a visible `Source` link to corresponding source for the exact deployed version and a link to the license and warranty notice.
- Third-party dependencies and assets retain their own licenses.
