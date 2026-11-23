# Finance reporting

Finance Reports opens on Analytics. Cash Summary, Receivables Aging, Payables Aging, Collections, weekly notes, print and CSV remain available.

- Cash figures use FinancePayment.paid_on, scoped through the receivable/payable relationship to one currency. Imported Afirmo entries and portal-issued entries use this ledger once. An existing receipt remains cash even if its invoice is later voided; orphan payments are excluded by the existing ledger scope.
- Cash movement is gross recorded receipts minus gross recorded payments, not accrual profit, a bank balance, tax treatment or foreign-exchange conversion. The comparison uses the immediately preceding equal number of calendar days. Weekly cash bars include zero weeks, and the exact amounts are available in an accessible table.
- Aging is a live snapshot using existing payment-ledger balances and aging bands, independent of the selected cash period. Drafts, voids, settled balances and overpayments are excluded. Due soon means today through six days from today. Largest overdue lists show up to five outstanding records, ordered by remaining balance, with links to the existing ledger search.
- Date ranges are ISO calendar dates, ordered and limited to five years. Currency changes and date presets retain the report. Applying custom dates clears a weekly preset. All amounts stay in their recorded currency.
- CSV exports include the cash window, previous period, current snapshot date, cash weeks and category totals. Text that could be interpreted as a spreadsheet formula is escaped. Export and print do not mutate records or send messages.

Design follows the existing Ticket Analytics green controls, summary cards, weekly chart, exact-data disclosure and drill-down pattern. The owner's Taste Vault dashboard reference was consulted. No new dependency, schema, background task, financial write or access permission was added.

Verification: FinanceAnalyticsTest exercises payment dates, exact decimals, mixed currencies, orphan and voided receipts, aging boundaries, drafts, overpayments, previous periods, weekly presets, zero weeks, read-only behavior, validation and Finance authorization. The frontend interaction test covers chart data, drill-down URLs, currency selection, date-state synchronization, CSV formula handling and empty states.
