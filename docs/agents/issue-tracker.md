# Issue tracker: GitHub

Issues and PRDs for this repo live as GitHub issues. Use the `gh` CLI for all operations.

The GitHub repository is `arickconley/signup_list`. The `gh` CLI infers it from the configured `origin` remote.

## Conventions

- **Create an issue**: `gh issue create --title "..." --body "..."`
- **Read an issue**: `gh issue view <number> --comments`
- **List issues**: `gh issue list --state open --json number,title,body,labels,comments`
- **Comment**: `gh issue comment <number> --body "..."`
- **Apply/remove labels**: `gh issue edit <number> --add-label "..."` or `--remove-label "..."`
- **Close**: `gh issue close <number> --comment "..."`

## Pull requests as a triage surface

**PRs as a request surface: no.**

## Skill operations

- “Publish to the issue tracker”: create a GitHub issue.
- “Fetch the relevant ticket”: run `gh issue view <number> --comments`.
- Resolve bare `#42` references as PRs first, then issues.

## Wayfinding

- A map is one issue labeled `wayfinder:map`.
- Child tickets use GitHub sub-issues when available; otherwise use task-list links.
- Use native GitHub issue dependencies when available.
- Claim work with `gh issue edit <number> --add-assignee @me`.
- Resolve work by commenting with the result, closing the issue, and updating the map.
