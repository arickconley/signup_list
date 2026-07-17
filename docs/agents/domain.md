# Domain Docs

## Before exploring

Read:

- Root `CONTEXT.md`, or relevant contexts from root `CONTEXT-MAP.md`.
- Applicable ADRs under `docs/adr/`.
- Context-specific ADRs when a multi-context layout exists.

Proceed silently when these files do not exist. Domain-modeling skills create them lazily.

## Layout

This repository uses a single context:

/
├── CONTEXT.md
├── docs/adr/
└── src/

## Vocabulary

Use canonical terms from `CONTEXT.md`. Avoid synonyms explicitly rejected there.

If a needed concept is absent, reconsider the term or note the gap for domain modeling.

## ADR conflicts

Explicitly flag output that conflicts with an existing ADR rather than silently overriding it.
