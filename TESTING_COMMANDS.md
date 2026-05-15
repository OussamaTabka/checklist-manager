# TESTING COMMANDS & API REFERENCE

## 🧪 Testing the Export Feature

### 1. Get Authentication Token

```bash
# Login to get token
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@example.com","password":"password"}'

# Response: {"token":"YOUR_TOKEN_HERE"}
```

### 2. Test Export Endpoints

#### Test JSON Export (Single Checklist)
```bash
TOKEN="your_token_here"
CHECKLIST_ID=1

curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/$CHECKLIST_ID/export/json \
  | jq .

# Or save to file
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/$CHECKLIST_ID/export/json \
  -o checklist_$CHECKLIST_ID.json
```

#### Test JSON Export (All Checklists)
```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/export/json \
  | jq .
```

#### Test CSV Export (Single Checklist)
```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/$CHECKLIST_ID/export/csv \
  -o checklist_$CHECKLIST_ID.csv

# View CSV content
cat checklist_$CHECKLIST_ID.csv
```

#### Test CSV Export (All Checklists)
```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/export/csv \
  -o all_checklists.csv
```

#### Test Excel Export (Single Checklist)
```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/$CHECKLIST_ID/export/excel \
  -o checklist_$CHECKLIST_ID.xlsx

# Verify file exists and has content
ls -lh checklist_$CHECKLIST_ID.xlsx
```

#### Test Excel Export (All Checklists)
```bash
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/export/excel \
  -o all_checklists.xlsx
```

---

## 🔍 Testing Autocomplete Feature

### 1. Load Available Items

```bash
TOKEN="your_token_here"

curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/items/available \
  | jq .

# Pretty print with colors
curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/items/available \
  | jq '.' -C
```

### 2. Sample Items Response

```json
[
  {
    "id": 1,
    "title": "Authentication Test",
    "description": "Validate login functionality",
    "priority": "High",
    "criticality": "Critical"
  },
  {
    "id": 2,
    "title": "Authorization Validation",
    "description": "Check user permissions",
    "priority": "High",
    "criticality": "Major"
  },
  {
    "id": 3,
    "title": "API Security Scan",
    "description": "Run OWASP security tests",
    "priority": "Medium",
    "criticality": "Major"
  }
]
```

### 3. Create Checklist with Autocomplete Item

```bash
TOKEN="your_token_here"

curl -X POST http://localhost:8000/api/checklists \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Security Checklist",
    "description": "Complete security validation",
    "category": "Security",
    "items": [
      {
        "id": 1,
        "title": "Authentication Test",
        "description": "Validate login functionality",
        "priority": "High",
        "criticality": "Critical"
      }
    ]
  }' | jq '.'
```

---

## 📊 Database Queries for Testing

### View Available Items in Database

```sql
-- Connect to your database
sqlite3 database.sqlite

-- Query all checklist items
SELECT id, title, description, priority, criticality 
FROM checklist_items 
ORDER BY title;

-- Count items
SELECT COUNT(*) as total_items FROM checklist_items;

-- Find items by first letter
SELECT * FROM checklist_items 
WHERE title LIKE 'A%' 
ORDER BY title;
```

### View Checklists with Item Counts

```sql
SELECT 
  c.id,
  c.name,
  c.category,
  c.is_active,
  COUNT(i.id) as item_count
FROM checklists c
LEFT JOIN checklist_items i ON c.id = i.checklist_id
GROUP BY c.id
ORDER BY c.id;
```

---

## 🐍 Python Testing Script

```python
import requests
import json
from datetime import datetime

# Configuration
API_URL = "http://localhost:8000/api"
EMAIL = "admin@example.com"
PASSWORD = "password"

def login():
    """Get authentication token"""
    response = requests.post(f"{API_URL}/login", json={
        "email": EMAIL,
        "password": PASSWORD
    })
    return response.json()["token"]

def test_export(token, checklist_id=None, format="json"):
    """Test export functionality"""
    if checklist_id:
        endpoint = f"/checklists/{checklist_id}/export/{format}"
    else:
        endpoint = f"/checklists/export/{format}"
    
    headers = {"Authorization": f"Bearer {token}"}
    response = requests.get(f"{API_URL}{endpoint}", headers=headers)
    
    if response.status_code == 200:
        filename = f"export_{datetime.now().strftime('%Y%m%d_%H%M%S')}.{format}"
        with open(filename, "wb") as f:
            f.write(response.content)
        print(f"✅ Export successful: {filename}")
        return filename
    else:
        print(f"❌ Export failed: {response.status_code}")
        return None

def test_autocomplete(token):
    """Test available items endpoint"""
    headers = {"Authorization": f"Bearer {token}"}
    response = requests.get(f"{API_URL}/checklists/items/available", headers=headers)
    
    if response.status_code == 200:
        items = response.json()
        print(f"✅ Autocomplete: Found {len(items)} items")
        for item in items[:3]:  # Show first 3
            print(f"   - {item['title']}")
        return items
    else:
        print(f"❌ Autocomplete failed: {response.status_code}")
        return None

# Run tests
if __name__ == "__main__":
    print("🧪 Running Tests...\n")
    
    # Login
    print("1️⃣  Logging in...")
    token = login()
    print(f"✅ Got token: {token[:20]}...\n")
    
    # Test export
    print("2️⃣  Testing exports...")
    test_export(token, checklist_id=1, format="json")
    test_export(token, checklist_id=1, format="csv")
    test_export(token, checklist_id=1, format="excel")
    print()
    
    # Test autocomplete
    print("3️⃣  Testing autocomplete...")
    items = test_autocomplete(token)
    print("\n✅ All tests completed!")
```

**Run the script:**
```bash
pip install requests
python test_features.py
```

---

## 🧠 JavaScript Testing in Browser Console

### Test Export Download

```javascript
// Get token from localStorage or auth store
const token = localStorage.getItem('auth_token'); // Adjust based on your auth

// Download JSON
async function downloadJSON() {
  const res = await fetch('/api/checklists/1/export/json', {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  const blob = await res.blob();
  downloadBlob(blob, 'checklist.json');
}

// Download CSV
async function downloadCSV() {
  const res = await fetch('/api/checklists/1/export/csv', {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  const blob = await res.blob();
  downloadBlob(blob, 'checklist.csv');
}

// Download Excel
async function downloadExcel() {
  const res = await fetch('/api/checklists/1/export/excel', {
    headers: { 'Authorization': `Bearer ${token}` }
  });
  const blob = await res.blob();
  downloadBlob(blob, 'checklist.xlsx');
}

// Helper to trigger download
function downloadBlob(blob, filename) {
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  URL.revokeObjectURL(url);
  console.log(`Downloaded: ${filename}`);
}

// Run tests
downloadJSON();  // Downloads as JSON
// downloadCSV();   // Downloads as CSV
// downloadExcel(); // Downloads as Excel
```

**In browser console:**
```javascript
// Copy and paste the code above, then run:
downloadJSON();
// Check Downloads folder - should have checklist.json
```

### Test Autocomplete Items Loading

```javascript
// Check what's in the page
const availableItems = document.querySelectorAll('.autocomplete-dropdown div');
console.log(`Found ${availableItems.length} autocomplete suggestions`);

// Manually load items (if component doesn't auto-load)
const token = localStorage.getItem('auth_token');
fetch('/api/checklists/items/available', {
  headers: { 'Authorization': `Bearer ${token}` }
})
.then(r => r.json())
.then(items => console.log('Available items:', items))
.catch(e => console.error('Error:', e));
```

---

## 📈 Performance Testing

### Test with Large Dataset

Create 100 test items:

```bash
TOKEN="your_token_here"

# Create items by creating multiple checklists
for i in {1..100}; do
  curl -X POST http://localhost:8000/api/checklists \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
      \"name\": \"Checklist $i\",
      \"items\": [{
        \"title\": \"Test Item $i\",
        \"priority\": \"Medium\",
        \"criticality\": \"Major\"
      }]
    }" > /dev/null
  echo "Created checklist $i"
done

# Now test autocomplete performance
time curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/items/available
```

### Monitor Export Performance

```bash
# For large export operations
time curl -H "Authorization: Bearer $TOKEN" \
  http://localhost:8000/api/checklists/export/csv \
  -o large_export.csv \
  -w "\nDownload speed: %{speed_download} bytes/sec\n"
```

---

## ✅ Checklist: Complete Testing

- [ ] User can see Export button on checklist table
- [ ] Export JSON downloads valid JSON file
- [ ] Export CSV opens correctly in Excel
- [ ] Export XLSX opens with formatting
- [ ] Autocomplete items load on page open
- [ ] Typing triggers dropdown suggestions
- [ ] Clicking suggestion populates all fields
- [ ] Can edit fields after autocomplete selection
- [ ] Custom items can be created (not in list)
- [ ] Search filters correctly (case-insensitive)
- [ ] No items shows = no dropdown appears
- [ ] Performance acceptable with 100+ items
- [ ] UTF-8 special characters work in export
- [ ] Export file naming includes timestamp
- [ ] Authentication required for all endpoints
- [ ] Admin-only restriction enforced

---

## 🔗 Quick Links

- **Full Documentation**: See `IMPLEMENTATION_GUIDE.md`
- **Quick Start**: See `QUICK_START.md`
- **Frontend Code**: `frontend/src/views/ChecklistsView.vue`
- **Backend Code**: `backend/app/Http/Controllers/Api/ChecklistController.php`
- **API Routes**: `backend/routes/api.php`
