# Registration Table (tblxFb5zXR3ZKtaw9) API Interaction Notes

## Field Type Guidelines:
- Single Select: Use choice IDs (e.g., sel8T2SQrTOD91XxF) or choice names (e.g., "Yes")
- Multiple Select: Array of choice IDs or names
- Linked Records: Array of record IDs from the linked table
- Attachments: Array of objects with filename, filetype, and url properties
- Formulas/Lookups/Rollups: Read-only fields, cannot be updated via API
- Dates: ISO 8601 format (YYYY-MM-DDTHH:mm:ss.sssZ)
- User Fields: User IDs in format usr...

# Bookings Table (tblETcytPcj835rb0) API Interaction Notes

## Field Type Guidelines:
- Single Select: Use choice IDs (e.g., selRRWRWBO3ztYdPR) or choice names (e.g., "PayPal")
- Linked Records: Array of record IDs from the linked table
- Numbers (Currency): Numeric values, displayed with $ symbol
- Formulas/Lookups/Rollups: Read-only fields, cannot be updated via API
- Dates: ISO 8601 format (YYYY-MM-DD)

## Choice ID Reference for Key Fields:

* Payment Method (flde9ksccaPhHGvNf):
    - selRRWRWBO3ztYdPR: PayPal
    - seloogLWA2yTkCL5D: Cash
    - selciBy06uJ5ijvlL: Stripe
    - selUQbZ6iuEHwdZoo: Not Applicable

* Payment Status (fldpZxt1Whc9jxptv):
    - seluEz2GO9KyrmWcs: Pending
    - sel6K2RJrpf9RqjaT: Paid
    - sel1WJ2PEhN9yh9BV: Not Required
    - sel0AKpy6HdHXg09u: Error
    - selAhseCCUcVPM9jP: Unpaid
    - selaQxkA5olaJG1K9: Other
    - sel6QJNl3yyolkk5m: Refunded
    - sel08phB3RY8WdYuk: Void

* Status (fldVOX4YsF09pN5x9):
    - selcs46Zf0phH4HHK: Pending
    - sel4nlOtZTGmQub0w: Complete
    - selDY9A2g5ianTePJ: Cancelled

# Booked Items Table (tbluEJs6UHGhLbvJX) API Interaction Notes

## Field Type Guidelines:
- Single Line Text: String values
- Linked Records: Array of record IDs from the linked table
- Numbers (Currency): Numeric values, displayed with $ symbol
- Checkbox: Boolean values (true/false)
- Formula: Read-only fields, cannot be updated via API

## Relationships:
- Linked to Bookings: Via fldR4XL4TOjWSJeG1 (Booking field)
- References Bookable Items: Via fldaEcURaSpwtHY2F (Bookable Item ID field)

# Check-Ins Table (tbluoEBBrpvvJnWak) API Interaction Notes

* Summary:
    - Total Fields: 7
    - Primary Field: Name (fldBB6VG989K2KJXf) - Formula field that concatenates First Name, Last Name, and Session
    - Linked Tables: Registrations (tblxFb5zXR3ZKtaw9)
    - Read-only Fields: Name (Formula), First Name (Lookup), Last Name (Lookup)
    - Lookup Fields: First Name and Last Name pull data from the linked Registrations table (fields fldZ9k6mSvKngXy3S and fldfO5MTnrNTS25Qh respectively)