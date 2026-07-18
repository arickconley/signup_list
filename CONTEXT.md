# Signups

This context describes shareable signup sheets and the people who create or respond to them.

## Language

**Owner**:
An account holder with a verified, non-disposable email address who creates and manages signup sheets. Any eligible account may become an owner.
_Avoid_: Admin, host, creator

**Account**:
A persistent identity that may participate in signup sheets and, when eligible, own them. Accounts begin with passwordless email access and may add other sign-in methods.
_Avoid_: Owner account, participant account, user

**Account Defaults**:
The current Account profile values used to initialize future Signup Sheets and Signups. New records copy applicable values, so later profile changes do not rewrite them.
_Avoid_: Live profile data, shared participant details

**Deleted Account**:
An account erased after fresh email verification and explicit confirmation. Its owned sheets are permanently deleted; its claims on other owners' sheets remain but all identifying details are replaced with “Deleted participant.”
_Avoid_: Deactivated account, suspended user

**Signup Sheet**:
A UUID-link-shared collection of options created by an owner for participants to claim. It has a required title, optional description, optional event date/time and location, and a positive selection maximum no greater than its number of options.
_Avoid_: Form, event, list

**Participation Policy**:
The sheet rule determining whether participants may sign up without an account or must verify an account first.
_Avoid_: Access mode, signup mode

**Open Participation**:
The default participation policy, allowing signups without email. Selection limits are enforceable per submission but can be bypassed by submitting again.
_Avoid_: Anonymous mode, public sheet

**Verified Participation**:
A participation policy requiring a verified account before signup. Each account has one signup per sheet, so the sheet's selection limit is enforceable per participant.
_Avoid_: Private sheet, members-only mode

**Draft Sheet**:
An unpublished signup sheet visible only to its owner and not yet accepting signups.
_Avoid_: Unpublished sheet

**Published Sheet**:
A signup sheet available through its shareable link. It accepts signups while open and remains publicly viewable when closed.
_Avoid_: Live sheet, active sheet

**Option**:
A manually ordered, named choice on a signup sheet, with an optional short description and an owner-set positive whole-number capacity.
_Avoid_: Slot, item

**Over-Capacity Option**:
An option whose owner-reduced capacity is lower than its existing claims. Existing claims remain, but no new claims are accepted.
_Avoid_: Oversold option, overbooked option

**Participant**:
A person who responds to a signup sheet. A participant may remain unregistered or provide an email address to establish a persistent account.
_Avoid_: Guest, respondent, user

**Unregistered Participant**:
A participant who signs up with a name and no email address, without establishing a persistent account.
_Avoid_: Anonymous participant, guest user

**Pending Account Association**:
The provisional relationship created when a participant signs up using an email address they have not yet verified. The signup completes immediately, but the account association is trusted only after verification.
_Avoid_: Unverified account ownership

**Signup**:
A participant's grouped response claiming one or more options on a signup sheet, up to an owner-set maximum. It retains the participant name and contact details used at submission time rather than changing with later account-profile edits.
_Avoid_: Submission, reservation

**Option Claim**:
One option selected within a signup, consuming exactly one unit of that option's capacity. A signup may contain several option claims without duplicating the participant's identity or contact details.
_Avoid_: Signup, reservation

**Participant Editing**:
The ability of a verified account participant to edit or cancel their existing signup while the sheet is open. Unregistered and pending-verification participants cannot edit; owners may always remove claims or entire signups.
_Avoid_: Self-service setting, guest editing

**Owner-Changed Signup**:
A signup from which its sheet owner removed an option claim or the entire signup. Owners cannot alter participant identity or add claims; account participants receive an email showing before/after selections and linking to the sheet.
_Avoid_: Admin edit

**Owner Digest**:
An optional account-wide daily or weekly email summarizing owned-sheet activity since the previous digest. Digests are disabled by default.
_Avoid_: Notification email, report

**Signup View**:
The owner's view of a sheet's signups, switchable between grouping by participant and grouping by option.
_Avoid_: Admin view, response list

**Print View**:
An owner-only, print-formatted HTML rendering of a sheet's signups, suitable for printing or saving as PDF.
_Avoid_: Export, report

**Participant Visibility**:
An owner-controlled setting determining whether participant names may be visible to people viewing the signup sheet. A participant may withhold their full name; initials are shown instead, while the owner retains access to the full name.
_Avoid_: Public signup

**Contact Visibility**:
Separate owner-controlled settings determining whether participant email addresses and phone numbers may be visible to people viewing the signup sheet. Both are owner-only by default, cannot be exposed retroactively without consent, and remain private when a participant refuses disclosure.
_Avoid_: Participant visibility

**Visibility Consent**:
A participant's permission to disclose their full name or specific contact fields on a signup sheet. Consent cannot be inferred from an owner's visibility setting and may be refused; owners retain access to submitted details.
_Avoid_: Public profile

**Signup Deadline**:
The last time participants may submit or edit signups. A new sheet's deadline defaults to 11:59 PM in the owner's timezone, 14 days after creation.
_Avoid_: Expiration date

**Closed Sheet**:
A published signup sheet that no longer accepts new signups, either because its deadline passed or its owner closed it manually. Its owner may reopen it by setting a future deadline.
_Avoid_: Expired sheet, disabled sheet

**Archived Sheet**:
A sheet retained permanently for its owner's private records but no longer available through its public link. Archiving cannot be reversed, except that deleting the owner account permanently deletes the sheet.
_Avoid_: Deleted sheet, hidden sheet

**Duplicate Sheet**:
A new draft with a new shareable identity and default deadline, copying the source sheet's content and settings but none of its signups or consent.
_Avoid_: Clone, template
