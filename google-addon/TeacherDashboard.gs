// ============================================================
//  MyTutor × Google Classroom — Teacher Dashboard
//  File: TeacherDashboard.gs
//
//  Adds a sidebar to Classroom (via Google Sheets or the
//  add-on card) so teachers can review student lesson requests.
// ============================================================

/**
 * Builds the teacher-facing card when the add-on is opened
 * in teacher mode (detected via Classroom role).
 */
function buildTeacherCard(e) {
  var addOnCtx = e && e.classroom && e.classroom.addOnContext
    ? e.classroom.addOnContext
    : null;

  var courseId = addOnCtx ? addOnCtx.courseId : null;
  var requests = getRecentLessonRequests(courseId);

  var card = CardService.newCardBuilder()
    .setName("teacher_dashboard")
    .setHeader(
      CardService.newCardHeader()
        .setTitle("MyTutor — Teacher View")
        .setSubtitle("Student lesson requests")
        .setImageUrl(CONFIG.LOGO_URL)
        .setImageStyle(CardService.ImageStyle.CIRCLE)
    );

  // ── Summary stats ─────────────────────────────────────────
  var statsSection = CardService.newCardSection()
    .setHeader("📊 At a Glance");

  statsSection.addWidget(
    CardService.newKeyValue()
      .setTopLabel("Pending Requests")
      .setContent(String(requests.pending || 0))
      .setIconUrl("https://fonts.gstatic.com/s/i/short-term/release/materialsymbolsoutlined/pending/default/24px.svg")
  );
  statsSection.addWidget(
    CardService.newKeyValue()
      .setTopLabel("Sessions Today")
      .setContent(String(requests.today || 0))
  );
  statsSection.addWidget(
    CardService.newKeyValue()
      .setTopLabel("Most Requested Topic")
      .setContent(requests.topTopic || "—")
  );

  card.addSection(statsSection);

  // ── Recent requests ───────────────────────────────────────
  var listSection = CardService.newCardSection()
    .setHeader("🕐 Recent Requests");

  if (!requests.items || requests.items.length === 0) {
    listSection.addWidget(
      CardService.newTextParagraph().setText("No requests yet for this course.")
    );
  } else {
    requests.items.forEach(function(req) {
      listSection.addWidget(
        CardService.newKeyValue()
          .setTopLabel(req.studentName + " — " + req.createdAt)
          .setContent(req.helpTopic || req.assignmentTitle || "General help")
          .setBottomLabel(req.status + " • " + req.sessionType)
          .setMultiline(true)
      );
    });
  }

  card.addSection(listSection);

  // ── Dashboard link ────────────────────────────────────────
  var linkSection = CardService.newCardSection();
  linkSection.addWidget(
    CardService.newButtonSet().addButton(
      CardService.newTextButton()
        .setText("Open Full Dashboard")
        .setOpenLink(
          CardService.newOpenLink()
            .setUrl("https://www.mytutor.co.uk/teachers/dashboard")
        )
    )
  );
  card.addSection(linkSection);

  return card.build();
}

/**
 * Fetches recent lesson requests for a course from the MyTutor API.
 * Falls back to mock data if no API key is set.
 */
function getRecentLessonRequests(courseId) {
  var apiKey = PropertiesService.getScriptProperties().getProperty("MYTUTOR_API_KEY");

  if (!apiKey) {
    // Demo / mock data
    return {
      pending:  3,
      today:    5,
      topTopic: "Quadratic Equations",
      items: [
        {
          studentName:     "Alex Johnson",
          assignmentTitle: "Algebra Unit 4 Quiz",
          helpTopic:       "Solving quadratics by factoring",
          sessionType:     "Live Session",
          status:          "Matched",
          createdAt:       "Today 9:14 AM",
        },
        {
          studentName:     "Maria Garcia",
          assignmentTitle: "Algebra Unit 4 Quiz",
          helpTopic:       "Completing the square method",
          sessionType:     "Async",
          status:          "Pending",
          createdAt:       "Today 10:02 AM",
        },
        {
          studentName:     "James Lee",
          assignmentTitle: "Chapter 3 Homework",
          helpTopic:       "I don't understand graphing parabolas",
          sessionType:     "Practice Pack",
          status:          "In Progress",
          createdAt:       "Yesterday 3:45 PM",
        },
      ],
    };
  }

  try {
    var url = CONFIG.MYTUTOR_API_BASE + "/lessons/list" +
      (courseId ? "?courseId=" + encodeURIComponent(courseId) : "");

    var response = UrlFetchApp.fetch(url, {
      headers: {
        "Authorization": "Bearer " + apiKey,
        "X-Source":      "google-classroom-addon-teacher",
      },
      muteHttpExceptions: true,
    });

    var body = JSON.parse(response.getContentText());
    return body.data || { pending: 0, today: 0, items: [] };
  } catch (err) {
    Logger.log("Teacher dashboard API error: " + err.message);
    return { pending: 0, today: 0, items: [], error: err.message };
  }
}

/**
 * Teacher action: manually trigger a lesson for a specific student.
 */
function teacherCreateLesson(e) {
  var formInputs = e.commonEventObject.formInputs;
  var studentEmail = formInputs.studentEmail
    ? formInputs.studentEmail[""].stringInputs.value[0] : "";
  var topic = formInputs.topic
    ? formInputs.topic[""].stringInputs.value[0] : "";

  var payload = {
    initiatedBy: "teacher",
    teacher:  { email: Session.getActiveUser().getEmail() },
    student:  { email: studentEmail },
    request: {
      helpTopic:   topic,
      urgency:     "medium",
      sessionType: "live",
      requestedAt: new Date().toISOString(),
    },
  };

  var result = callMyTutorApi("/lessons/create", payload);

  var nav = CardService.newNavigation();
  nav.pushCard(buildConfirmationCard(result, "live"));
  return nav;
}
