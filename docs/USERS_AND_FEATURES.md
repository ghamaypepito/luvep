# ePathways — Users, Roles & Features

**Reference document** · generated from source (`app/Models/User.php`, `EnsurePortalAccess`, portal layouts, `routes/web.php`)
**Last updated:** 2026-07-21

This document lists every user role in the system, what each can access, and the features available in each portal. It is the authoritative "who can do what" reference.

---

## 1. How access works (in one minute)

- Every user has a single **`role`** column. There is no multi-role assignment — one account, one role.
- After login, a user lands on their **home route** (`User::homeRoute()`) and can only reach areas their role permits.
- Route protection is the **`portal:<role>`** middleware. Listing several roles means "any of these" (OR). Example: `portal:admin,immigration` = admins **or** immigration staff.
- **Admins and super-admins pass every portal check** — they can open any department's screens. `super_admin`-only surfaces (Super Dashboard, Maintenance Mode) are the exception: plain admins are kept out by an exact-role check.

---

## 2. Current accounts in this system

| # | Role | Name | Email |
|---|------|------|-------|
| 1 | `super_admin` | Admin | support@epathways.co.nz |
| 2 | `finance` | Finance | finance@epathways.co.nz |
| 3 | `education` | Bryle | educ@epathways.co.nz |
| 5 | `agent` | lilian | lilian@gmail.com |

*4 accounts total. New staff accounts are created in **Admin → User Management**; external client accounts are created through the **Portal Invitation** flow (Sales requests → Admin approves → credentials generated).*

---

## 3. The role hierarchy

12 roles exist, in 4 tiers. "Rank" is the numeric power weight used by `isAtLeast()`.

| Role | Rank | Tier | Lands on | In sidebar "Portals"? |
|------|:----:|------|----------|:---:|
| `super_admin` | 100 | Top of house | `/admin/super-dashboard` | — (is admin) |
| `admin` | 80 | Full access | `/admin/dashboard` | — (is admin) |
| `immigration_manager` | 50 | Dept head | `/portal/immigration/dashboard` | Immigration |
| `immigration_adviser` | 20 | Immigration adviser | `/portal/immigration/dashboard` | Immigration |
| `sales` | — | Department | `/portal/sales/dashboard` | Sales |
| `education` | — | Department | `/portal/education/dashboard` | Education |
| `english` | — | Department | `/portal/english/dashboard` | English |
| `immigration` | — | Department | `/portal/immigration/dashboard` | Immigration |
| `accommodation` | — | Department | `/portal/accommodation/dashboard` | Accommodation |
| `finance` | — | Department | `/portal/finance/dashboard` | Finance |
| `agent` | — | External recruiter | `/portal/agent/dashboard` | Agent |
| `lead` | — | External client | `/portal/lead/dashboard` | — (client) |

---

## 4. Role-by-role features

### 👑 Super Admin
Everything an Admin has, **plus**:
- **Super Dashboard** — cross-department overview & AI-eligibility distribution
- **Maintenance Mode** — take the public site offline (staff/admin unaffected), with custom message, ETA, scheduled window, and a preview/bypass link
- Can hard-delete and edit anything across all departments.

### 🛡️ Admin
Full run of the business. Sidebar features:
- **Dashboard**
- **Leads** — List of Leads · Proposals & Agreements
- **Document Queue** — review/approve submitted client documents
- **Portal Invitations** — approve/reject/revoke client portal access, generate credentials
- **Events** · **Bookings** · **Availability**
- **Programs** · **Schools** · **Promotions**
- **Facebook Live** · **Visa Approved**
- **Social** — Compose · Scheduled · Inbox · Ads · Performance · Accounts
- **User Reviews** (unified moderation)
- **Email** — Templates · Bulk Mail · SMS · Replies
- **System Tickets**
- **Immigration** — Dashboard · Resident Visa Intake
- **Portals** — deep-links into every department portal (Sales/Education/English/Immigration/Accommodation/Finance/Agent)
- **User Management** — create/edit staff & agent accounts (with photo, location, contact)
- **All Tasks** · **Activity Log**

### 🧭 Immigration (`immigration`, `immigration_manager`, `immigration_adviser`)
All three resolve to the **Immigration portal**. Difference is edit rights:
- **`immigration_manager`** — department head; can edit visa types **including price**.
- **`immigration_adviser`** — full case write and visa catalogue CRUD, including fees. Advice-bearing approval requires a current IAA licence, independently of role. Catalogue access was expanded on 2026-08-22 (`fba4a056`); see [`immigration-architecture.md`](immigration-architecture.md#2-roles--access-control).
- **`immigration`** — standard department staff.

Portal features:
- **Dashboard** · **Work** (task board)
- **Visa Assessment**
- **Leads** — List of Leads · Proposals & Agreements
- **Case** — List of Cases · Engagement · Invoice
- **Students** · **Documents** · **Task Board** · **Appointments**
- **Setup** — Visas · Intakes · INZ Forms · Checklist Templates
- **Email** — Templates · Bulk Mail · SMS · Replies
- **Reports** · **Visa Approved**
- **Account** — My Profile · Notifications · My Tickets

### 💼 Sales
- **Dashboard** · **Work** (task board)
- **Leads** — List of Leads · Proposals & Agreements
- **Students** · **Task Board** · **Assessments** · **Bookings**
- **Programs** · **Reports** · **Visa Approved**
- **Outreach** — Promotions · Bulk Email · Email Templates · Campaigns
- **Account** — My Profile · Notifications · My Tickets

### 🎓 Education
- **Dashboard** · **Work** (task board)
- **Leads** — List of Leads · Proposals & Agreements
- **Task Board** · **Assessments** · **Students** · **Documents**
- **User Reviews**
- **Setup** — Programs · Schools · Promotions · Checklist Templates
- **Email** — Templates · Bulk Mail · SMS · Replies
- **Reports** · **Visa Approved**
- **Account** — My Profile · Notifications · My Tickets

### 🗣️ English
Lean teaching-focused portal:
- **Dashboard**
- **Classes** · **Learners** · **Assessments**
- **Email Templates**
- **Account** — My Tickets

### 🏠 Accommodation
- **Dashboard** · **Work** (task board)
- **Tenants** · **Onboarding** · **Viewings** · **Calendar**
- **Rent & Utilities** · **PM Payment Schedule** · **Task Tracker** · **Gas Delivery Tracker**
- **Email Templates**
- **Setup** — Properties · Reports
- **Account** — My Profile · Notifications · My Tickets

### 💰 Finance
Minimal portal (placeholder for a future finance module):
- **Dashboard**
- **Task Board**

### 🤝 Agent (external recruiter)
Restricted, recruit-only portal:
- **Dashboard** · **Work** (task board)
- **My Leads** — only the leads this agent added
- **Can:** add & edit lead info for their own leads.
- **Cannot:** change lead stages, delete leads, or see other agents' leads. No notifications, no tickets.

### 👤 Lead (external client)
Client-facing self-service portal:
- **Dashboard** · **Work**
- **My Journey** — Submit · Documents · Checklist · Visa Forms
- **Engage** — Appointments · Proposals · Agreements · Payments
- **Stay in touch** — Messages · News & Tips · Events
- **Account** — My Profile

---

## 5. Notes & gotchas

- **One role per account.** To give someone broader access, use `admin` (all departments) rather than stacking roles.
- **Admins see everything**, so a bug or destructive action reachable in one department is reachable by every admin — worth remembering when auditing.
- **Immigration advice-bearing approval is licence-gated**, including for admins and advisers; ordinary case and catalogue writes follow their role policies. Do not restore the superseded adviser-wide read-only rule.
- **Some `/admin/immigration/...` screens are shared** between admins and immigration staff (`portal:admin,immigration`); the immigration portal deep-links into them.
- **Agents and Leads are external** — they only ever see their own data.

---

*This document reflects the roles and portal navigation as defined in code on branch `full_blown`. If a portal's sidebar changes, regenerate from the layout files in `resources/js/components/layout/`.*
