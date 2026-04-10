# MyTutor × Google Classroom Add-on
## Setup & Deployment Guide

---

### What this does

When a student opens an assignment in **Google Classroom**, they can click the
**MyTutor** add-on button. The script automatically reads:

- Course name & subject
- Assignment title, description, and due date
- Attached materials (Drive files, YouTube links, Forms)

…then lets the student describe what they're stuck on, choose a session type
(Live / Async / Practice), and fire a lesson request directly to MyTutor —
pre-populated with all the assignment context.

Teachers get a dashboard card showing all student requests for their course.

---

### File Structure

```
mytutor-addon/
├── Code.gs              ← Main add-on logic, card builders, Classroom API calls
├── TeacherDashboard.gs  ← Teacher-facing cards and lesson management
└── appsscript.json      ← Add-on manifest (OAuth scopes, triggers)
```

---

### Step-by-Step Setup

#### 1. Create the Apps Script project

1. Go to [script.google.com](https://script.google.com) → **New Project**
2. Name it **MyTutor Classroom Add-on**
3. Delete the default `Code.gs` content

#### 2. Add the files

Paste each `.gs` file into a corresponding script file:
- Rename the default `Code.gs` and paste `Code.gs` content
- Click **＋** → **Script** → name it `TeacherDashboard` → paste content
- Click **Project Settings** (⚙️) → enable **Show `appsscript.json`**
- Paste the `appsscript.json` content into that manifest file

#### 3. Enable Advanced Services

In the Apps Script editor:
1. Click **Services** (＋) in the left sidebar
2. Find **Google Classroom API** → click **Add**
3. This enables `Classroom.*` calls in your code

#### 4. Store your MyTutor API key

In the Apps Script editor, open the terminal or run this once:
```javascript
// Paste your key and run setApiKey() from the editor
function setApiKey() {
  PropertiesService.getScriptProperties()
    .setProperty("MYTUTOR_API_KEY", "YOUR_REAL_KEY_HERE");
}
```
> ⚠️ Never hard-code API keys in source files.

#### 5. Deploy as a Google Workspace Add-on

1. Click **Deploy** → **New deployment**
2. Select type: **Add-on**
3. Fill in description, click **Deploy**
4. Copy the **Deployment ID**

#### 6. Publish to Google Workspace Marketplace

1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Create / select your project → **APIs & Services → Credentials**
3. Set up the OAuth consent screen (External or Workspace)
4. In **Google Workspace Marketplace SDK** → configure your add-on listing
5. Submit for review (or install directly for your domain as an admin)

---

### MyTutor API Integration

The script calls two MyTutor endpoints. Work with the MyTutor team to
implement these on their side:

#### `POST /v1/lessons/create`

**Request body:**
```json
{
  "student": {
    "id": "google-user-id",
    "email": "student@school.edu"
  },
  "classroom": {
    "courseId": "123456",
    "courseWorkId": "789",
    "courseName": "Year 10 Maths",
    "subject": "Mathematics",
    "assignmentTitle": "Algebra Unit 4 Quiz",
    "assignmentDescription": "Complete questions 1–20...",
    "dueDate": "4/15/2026",
    "materials": [
      { "type": "Drive", "title": "Worksheet", "url": "https://..." }
    ]
  },
  "request": {
    "helpTopic": "I don't understand quadratic equations",
    "urgency": "high",
    "sessionType": "live",
    "requestedAt": "2026-04-09T14:23:00Z"
  }
}
```

**Expected response:**
```json
{
  "success": true,
  "lessonId": "MT-ABC123",
  "tutorName": "Sarah T.",
  "scheduledAt": "Within 1 hour",
  "dashboardUrl": "https://mytutor.co.uk/students/dashboard"
}
```

#### `GET /v1/lessons/list?courseId=123456`

Returns aggregated lesson request stats and recent items for the teacher view.

---

### OAuth Scopes Used

| Scope | Reason |
|---|---|
| `classroom.courses.readonly` | Read course name and subject |
| `classroom.coursework.students.readonly` | Read assignment details |
| `classroom.rosters.readonly` | Identify student in course |
| `classroom.addons.student` | Show UI to students |
| `classroom.addons.teacher` | Show dashboard to teachers |
| `script.external_request` | Call the MyTutor API |
| `userinfo.email` / `profile` | Identify the current user |

---

### Testing Without API Keys

The script includes a **mock mode** — when no `MYTUTOR_API_KEY` is stored,
`callMyTutorApi()` returns realistic fake data so you can test the full UI
flow before connecting the real API.

---

### Customisation Tips

- **Logo**: Replace `CONFIG.LOGO_URL` in `Code.gs` with your hosted logo URL
- **Subjects**: Classroom doesn't expose a "subject" field natively; you can
  add a subject-mapping object keyed by courseId if needed
- **Notifications**: Add `GmailApp.sendEmail()` calls in `requestTutorLesson()`
  to send a confirmation email to the student
- **Logging**: All API errors are sent to **Stackdriver Logs**
  (View → Logs in the Apps Script editor)
