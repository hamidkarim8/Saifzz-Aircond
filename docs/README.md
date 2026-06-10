# Saifzz Aircond — Service Management System

Development reference documentation. Day-1 baseline (2026-06-10).

Saifzz Aircond is a service-management web application for a Malaysian air-conditioning
servicing business. It replaces ad-hoc record keeping with a single system covering
clients, service records, payments, appointments, reminders, and a self-service client
portal.

This `docs/` set is the source of truth for **what** the system does and **why**.
The technology stack and deployment specifics are documented separately once chosen.

---

## Document index

| File | Contents |
|------|----------|
| [01-product-overview.md](01-product-overview.md) | Vision, actors, surfaces, scope, what's out of scope |
| [02-domain-model.md](02-domain-model.md) | Entities, relationships, business rules, lifecycles |
| [03-rbac-permissions.md](03-rbac-permissions.md) | Roles, granular permissions, default minimum set |
| [04-feature-modules.md](04-feature-modules.md) | The 11 modules, responsibilities, user stories |
| [05-design-system.md](05-design-system.md) | UI/UX, responsive rules (mobile/iPad/desktop), design tokens |
| [06-integrations.md](06-integrations.md) | Payment gateway, WhatsApp, PDF — requirements & contracts |

The original clickable mockup lives at [`../index.html`](../index.html) (Service System v4).
It is the visual + behavioural reference; treat it as authoritative for flows, and as a
strong-but-improvable reference for visual design.

---

## Decisions locked (brainstorming, 2026-06-10)

| Topic | Decision |
|-------|----------|
| Owner | **Khalid** — admin / superuser, sole permission grantor |
| Users | Owner + technicians; **granular per-technician permissions**, default minimum |
| Surfaces | Admin app (auth) + public client portal; one backend |
| Payment | **Full gateway integration** — DuitNow QR with webhook auto-verify, plus Cash |
| Transactions | **Every service visit creates a Transaction** (paid → Receipt, else pending → Invoice) |
| WhatsApp | **wa.me click-to-chat now**, architecture ready for Meta Cloud API later |
| Documents | On-screen view **and** server-generated PDF (invoice + receipt) |
| Appointments | Separate from service visits, loosely linked (appt can pre-fill a visit) |
| Serial number | **One serial = one client** (account number on remote sticker) |
| Warranty | **Per visit** (0–6 months), not per unit |
| Responsiveness | First-class **mobile + iPad + desktop**; technicians work onsite on phones |
| Scale / host | Build for thousands of clients; **Docker single-VM** — GCP free VM (2 mo) → Linux VPS |

## Open / deferred

- **Technology stack** — to be decided next (frontend, backend, DB, PDF lib, gateway choice).
- **Deployment doc** — Dockerfile, compose, GCP→VPS migration — written after stack lock.
- Out of scope for v1: inventory/parts, technician routing/GPS, multi-branch,
  accounting/SST, email, multi-language.
