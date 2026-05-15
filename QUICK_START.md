# QUICK START: Export & Autocomplete Features

## 🚀 QUICK START - EXPORT CHECKLISTS

### For End Users (5 minutes)

**To export a checklist:**
1. Go to **Checklists** section (admin users only)
2. Find the checklist in the list
3. Click **⬇ Export** button next to the checklist
4. Choose format:
   - **📄 JSON** - For data processing/APIs
   - **📊 CSV** - For Excel/Google Sheets
   - **📈 Excel** - For native .xlsx files
5. File downloads automatically with timestamp

**Export file naming:**
- Format: `checklist_[ID]_[DATE]_[TIME].[format]`
- Example: `checklist_5_2026-03-11_14-30-45.xlsx`

---

## 🔍 QUICK START - AUTOCOMPLETE ITEMS

### For End Users (5 minutes)

**To reuse existing items when creating a checklist:**

1. Go to **Checklists** → **Create checklist** form
2. Under **Items** section, start typing in the "Title" field
3. A dropdown appears showing matching items:
   - Shows items starting with your typed letter(s)
   - Shows: Title, Description, Priority, Criticality
4. **Click any item** to auto-fill all fields
5. Fields populate automatically:
   - ✅ Title
   - ✅ Description  
   - ✅ Priority
   - ✅ Criticality
6. Can edit any field after selection
7. Click **"Create checklist"** to save

**Search examples:**
- Type "A" → See all items starting with "A"
- Type "Au" → See items starting with "Au"
- Type "Aut" → See even fewer, more specific items
- No matches? Create new custom item

---

## 👨‍💻 FOR DEVELOPERS

### Export Implementation Checklist

- [x] Backend export methods added to `Api/ChecklistController.php`
- [x] Three export formats: JSON, CSV, XLSX
- [x] Routes added: `/api/checklists/{id}/export/{format}`
- [x] Frontend export buttons in `ChecklistsView.vue`
- [x] File download with proper headers
- [x] Error handling implemented

**What was added:**
```php
// Backend methods
exportJson($id = null)       // Export as JSON
exportCsv($id = null)        // Export as CSV
exportExcel($id = null)      // Export as XLSX
generateExcelXml($checklists) // Helper for XML format
```

### Autocomplete Implementation Checklist

- [x] Backend endpoint: `GET /api/checklists/items/available`
- [x] Database query for unique items
- [x] Frontend item loading on mount
- [x] Real-time search filtering
- [x] Dropdown UI with suggestions
- [x] Auto-populate form on selection

**What was added:**
```javascript
// Frontend features
loadAvailableItems()           // Load all items from API
getFilteredItems(index)        // Filter by search query
selectExistingItem(index, item) // Auto-populate form
itemSearchQueries               // Track user input
```

---

## 📋 API ENDPOINTS SUMMARY

### Export Endpoints (All require authorization)

```
GET /api/checklists/export/json           Export all as JSON
GET /api/checklists/{id}/export/json      Export one as JSON
GET /api/checklists/export/csv            Export all as CSV
GET /api/checklists/{id}/export/csv       Export one as CSV  
GET /api/checklists/export/excel          Export all as XLSX
GET /api/checklists/{id}/export/excel     Export one as XLSX
```

### Autocomplete Endpoint

```
GET /api/checklists/items/available       Get all available items
```

---

## 🎨 FRONTEND COMPONENTS MODIFIED

### ChecklistsView.vue Changes

**Script section added:**
- `availableItems` - State for loaded items
- `itemSearchQueries` - Track search per item field  
- `loadAvailableItems()` - Load from API
- `getFilteredItems(index)` - Real-time filtering
- `selectExistingItem(index, item)` - Auto-populate
- `exportChecklist(id, format)` - Download file

**Template section added:**
- Export dropdown with 3 format buttons
- Autocomplete dropdown below item title
- Suggestion display with details
- Click handlers for selection

---

## 🔧 CUSTOMIZATION GUIDE

### Customize Export Columns

In `ChecklistController::exportCsv()`:
```php
// Change CSV columns here
fputcsv($file, ['Checklist Name', 'Category', 'Status', 'Item Title', ...]);
```

### Customize Autocomplete Filtering

In `ChecklistsView.vue`:
```javascript
// Currently: starts with (case-insensitive)
getFilteredItems: (itemIndex) => {
  return availableItems.value.filter(item =>
    item.title.toLowerCase().startsWith(query.toLowerCase())  // ← Change this
  )
}

// Alternative: contains anywhere
item.title.toLowerCase().includes(query.toLowerCase())

// Alternative: exact match
item.title.toLowerCase() === query.toLowerCase()
```

### Change Max Suggestions in Dropdown

In `ChecklistsView.vue` template:
```vue
<div v-if="getFilteredItems(index).length > 0">
  <!-- Add .slice(0, 5) to limit to 5 items -->
  <div v-for="suggestion in getFilteredItems(index).slice(0, 5)">
```

---

## 📊 EXAMPLE USAGE

### Export via JavaScript

```javascript
const username = "admin";
const password = "password";
const checklistId = 1;

// 1. Login to get token
const loginRes = await fetch("/api/login", {
  method: "POST",
  headers: { "Content-Type": "application/json" },
  body: JSON.stringify({ email: username, password })
});
const { token } = await loginRes.json();

// 2. Export checklist
const exportRes = await fetch(
  `/api/checklists/${checklistId}/export/csv`,
  { headers: { "Authorization": `Bearer ${token}` } }
);

// 3. Download file
const blob = await exportRes.blob();
const url = URL.createObjectURL(blob);
const a = document.createElement("a");
a.href = url;
a.download = `checklist_${checklistId}.csv`;
a.click();
```

### Autocomplete Search Example

When user types in item title field:
```
Typed: "A"
Results shown:
  1. "Authentication Check" 
  2. "API Testing"

Typed: "AU" 
Results shown:
  1. "Authentication Check"

Typed: "AUT"
Results shown:
  1. "Authentication Check"

Typed: "AUTH"
Results shown:
  1. "Authentication Check"

User clicks → Item populates with all data
```

---

## ⚠️ IMPORTANT NOTES

1. **Admin Only**
   - Export feature only visible to admin users
   - Autocomplete available to admins creating/editing checklists

2. **Authentication Required**
   - All API endpoints require valid Bearer token
   - Token from login endpoint must be included

3. **Performance**
   - Items loaded once on page mount
   - Filtering done client-side (fast)
   - Export generates on-demand (server-side)

4. **File Format Notes**
   - **JSON**: Best for APIs/system integration
   - **CSV**: Best for Excel spreadsheets
   - **Excel**: Best for native .xlsx opening

5. **UTF-8 Support**
   - CSV includes UTF-8 BOM for Excel
   - Special characters (é, ñ, etc.) supported
   - Works with multiple languages

---

## 🐛 TROUBLESHOOTING QUICK FIXES

| Problem | Quick Fix |
|---------|-----------|
| Export button doesn't appear | Check user is admin |
| Export file is empty | Verify API token is valid |
| Autocomplete doesn't show | Refresh page → try again |
| Search filtering doesn't work | Check browser console for errors |
| Excel won't open downloaded file | Ensure filename ends in `.xlsx` |
| Special characters garbled in CSV | Open with UTF-8 encoding in Excel |

---

## 📞 NEXT STEPS

1. **Test the features**
   - Create a test checklist
   - Export in all 3 formats
   - Try autocomplete search

2. **Review the full documentation**
   - See `IMPLEMENTATION_GUIDE.md` for complete details

3. **Customize if needed**
   - Adjust column names
   - Change filtering logic
   - Modify UI styling

4. **Deploy to production**
   - Test on staging first
   - Ensure API endpoints are secured
   - Monitor performance with many items
