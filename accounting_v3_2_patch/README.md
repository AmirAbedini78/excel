# Accounting CRM V3.2 Patch

Adds:

- Bulk delete to all smart-table lists.
- Bulk delete to Kanban cards.
- Professional CSV/XLSX import/export.
- XLSX/CSV template downloads.
- Export selected rows; if no rows are selected, export all records in that module.
- Calendar event "Open" links that navigate to the exact row.
- Quick daily/monthly creation directly from calendar.
- Daily plan UI no longer exposes "location"; duration was not a core field and is not added.
- Mobile/responsive styling for the new controls.

## Apply on Windows

Place the `accounting_v3_2_patch` folder inside the project root.

PowerShell:

```powershell
Set-ExecutionPolicy -Scope Process Bypass
.\accounting_v3_2_patch\apply_accounting_v3_2_patch.ps1
```

Then:

```powershell
git status
git add .
git commit -m "Add bulk delete, import export and calendar quick actions V3.2"
git pull --rebase origin main
git push origin main
```

cPanel:
- Update from Remote
- Deploy HEAD Commit

No database migration is required for V3.2.
