# Webspaces (Webhosting MVP)

## Overview
This document describes the webspace provisioning flow and the related API/UI entry points.

## Manual test steps
1. Create a webspace in the admin UI: **Admin → Webspaces → Create**.
   - Select a customer and node.
   - Provide a web root path; leave the document root empty to use `/public`.
2. Confirm a `webspace.create` job is queued in **Admin → Jobs**.
3. Verify the webspace list shows status badges and the expected docroot/path.
4. Suspend and resume the webspace and confirm audit log entries.
5. Soft-delete the webspace and confirm it disappears from customer lists.

## API checks
1. `POST /api/v1/admin/webspaces` with required fields to create.
2. `GET /api/v1/admin/webspaces` to list.
3. `POST /api/v1/admin/webspaces/{id}/suspend` and `/resume` to change status.
4. `DELETE /api/v1/admin/webspaces/{id}` to soft delete.
5. `GET /api/v1/customer/webspaces` to confirm customer visibility.
