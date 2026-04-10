// ============================================================
//  MyTutor × Google Classroom Add-on
//  File: Code.gs  —  Main entry point
// ============================================================

// ── CONFIG ──────────────────────────────────────────────────
// Replace with your real MyTutor API credentials
const CONFIG = {
  MYTUTOR_API_BASE: "https://api.mytutor.com/v1",  // placeholder
  MYTUTOR_API_KEY:  PropertiesService.getScriptProperties().getProperty("MYTUTOR_API_KEY"),
  ADDON_TITLE:      "MyTutor",
  LOGO_URL:         "https://i.imgur.com/placeholder-logo.png", // swap your logo
};

// ── ADD-ON LIFECYCLE ─────────────────────────────────────────

/**
 * Called when the add-on is installed.
 */
function onInstall(e) {
  onOpen(e);
}

/**
 * Builds the add-on card when a student opens it from a
 * Classroom assignment or stream item.
 */
function onOpen(e) {
  return buildHomeCard(e);
}

/**
 * Required trigger for Classroom add-ons.
 * Fired when a student launches the add-on from an attachment.
 */
function onClassroomAddonTrigger(e) {
  return buildContextCard(e);
}

// ── CARD BUILDERS ────────────────────────────────────────────

/**
 * Home card shown when no Classroom context is available
 * (e.g. opened from Drive / Docs).
 */
function buildHomeCard(e) {
  var card = CardService.newCardBuilder()
    .setName("home")
    .setHeader(
      CardService.newCardHeader()
        .setTitle("MyTutor")
        .setSubtitle("Your personal learning companion")
        .setImageUrl(CONFIG.LOGO_URL)
        .setImageStyle(CardService.ImageStyle.CIRCLE)
    );

  var section = CardService.newCardSection()
    .addWidget(
      CardService.newTextParagraph().setText(
        "👋 Open MyTutor from inside a <b>Google Classroom assignment</b> " +
        "to instantly request a personalised tutoring lesson on that topic."
      )
    )
    .addWidget(
      CardService.newButtonSet().addButton(
        CardService.newTextButton()
          .setText("Go to Google Classroom")
          .setOpenLink(
            CardService.newOpenLink().setUrl("https://classroom.google.com")
          )
      )
    );

  card.addSection(section);
  return card.build();
}

/**
 * Context-aware card — shown when the add-on is opened
 * from within a specific Classroom course / activity.
 */
function buildContextCard(e) {
  var addOnCtx = e && e.classroom && e.classroom.addOnContext
    ? e.classroom.addOnContext
    : null;

  // Pull Classroom context
  var courseId      = addOnCtx ? addOnCtx.courseId      : null;
  var courseWorkId  = addOnCtx ? addOnCtx.courseWorkId  : null;
  var studentId     = addOnCtx ? addOnCtx.userId        : Session.getActiveUser().getEmail();

  var activityInfo  = fetchClassroomActivity(courseId, courseWorkId);
  var student       = fetchStudentProfile(courseId, studentId);

  var card = CardService.newCardBuilder()
    .setName("context")
    .setHeader(
      CardService.newCardHeader()
        .setTitle("MyTutor")
        .setSubtitle("Get help with this assignment")
        .setImageUrl(CONFIG.LOGO_URL)
        .setImageStyle(CardService.ImageStyle.CIRCLE)
    );

  // ── Assignment Summary ────────────────────────────────────
  var assignmentSection = CardService.newCardSection()
    .setHeader("📚 Assignment Details");

  assignmentSection.addWidget(
    CardService.newKeyValue()
      .setTopLabel("Subject")
      .setContent(activityInfo.subject || activityInfo.courseName || "Unknown Subject")
  );
  assignmentSection.addWidget(
    CardService.newKeyValue()
      .setTopLabel("Assignment")
      .setContent(activityInfo.title || "Untitled Assignment")
  );

  if (activityInfo.dueDate) {
    assignmentSection.addWidget(
      CardService.newKeyValue()
        .setTopLabel("Due Date")
        .setContent(activityInfo.dueDate)
    );
  }

  if (activityInfo.description) {
    assignmentSection.addWidget(
      CardService.newKeyValue()
        .setTopLabel("Description")
        .setContent(activityInfo.description.substring(0, 200) +
          (activityInfo.description.length > 200 ? "…" : ""))
        .setMultiline(true)
    );
  }

  card.addSection(assignmentSection);

  // ── Student Input ─────────────────────────────────────────
  var inputSection = CardService.newCardSection()
    .setHeader("🙋 What do you need help with?");

  inputSection.addWidget(
    CardService.newTextInput()
      .setFieldName("helpTopic")
      .setTitle("Specific topic or question")
      .setHint("e.g. 'I don't understand quadratic equations'")
      .setMultiline(true)
  );

  inputSection.addWidget(
    CardService.newSelectionInput()
      .setType(CardService.SelectionInputType.DROPDOWN)
      .setTitle("How urgent is this?")
      .setFieldName("urgency")
      .addItem("ASAP — I have a test soon!",   "high",   false)
      .addItem("Within the next day or two",   "medium", true)
      .addItem("No rush — just want to learn", "low",    false)
  );

  inputSection.addWidget(
    CardService.newSelectionInput()
      .setType(CardService.SelectionInputType.DROPDOWN)
      .setTitle("Preferred session type")
      .setFieldName("sessionType")
      .addItem("Live 1-to-1 Video Session",  "live",  true)
      .addItem("Async — Written Explanation", "async", false)
      .addItem("Practice Questions",          "practice", false)
  );

  card.addSection(inputSection);

  // ── Request Button ────────────────────────────────────────
  var btnSection = CardService.newCardSection();

  var requestAction = CardService.newAction()
    .setFunctionName("requestTutorLesson")
    .setParameters({
      courseId:     courseId     || "",
      courseWorkId: courseWorkId || "",
      studentId:    studentId   || "",
      activityJson: JSON.stringify(activityInfo),
    });

  btnSection.addWidget(
    CardService.newButtonSet().addButton(
      CardService.newTextButton()
        .setText("🎓 Request MyTutor Lesson")
        .setTextButtonStyle(CardService.TextButtonStyle.FILLED)
        .setBackgroundColor("#6C2DC7")
        .setOnClickAction(requestAction)
    )
  );

  card.addSection(btnSection);
  return card.build();
}

// ── ACTION HANDLERS ──────────────────────────────────────────

/**
 * Fired when the student taps "Request MyTutor Lesson".
 * Collects form data, calls the MyTutor API, and shows a
 * confirmation card.
 */
function requestTutorLesson(e) {
  var formInputs   = e.commonEventObject.formInputs;
  var params       = e.commonEventObject.parameters;

  var helpTopic    = formInputs.helpTopic   ? formInputs.helpTopic[""].stringInputs.value[0]   : "";
  var urgency      = formInputs.urgency     ? formInputs.urgency[""].stringInputs.value[0]     : "medium";
  var sessionType  = formInputs.sessionType ? formInputs.sessionType[""].stringInputs.value[0] : "live";

  var activityInfo = JSON.parse(params.activityJson || "{}");

  // Payload sent to MyTutor
  var lessonRequest = {
    student: {
      id:    params.studentId,
      email: Session.getActiveUser().getEmail(),
    },
    classroom: {
      courseId:     params.courseId,
      courseWorkId: params.courseWorkId,
      courseName:   activityInfo.courseName,
      subject:      activityInfo.subject,
      assignmentTitle: activityInfo.title,
      assignmentDescription: activityInfo.description,
      dueDate:      activityInfo.dueDate,
      materials:    activityInfo.materials || [],
    },
    request: {
      helpTopic:   helpTopic,
      urgency:     urgency,
      sessionType: sessionType,
      requestedAt: new Date().toISOString(),
    },
  };

  var result = callMyTutorApi("/lessons/create", lessonRequest);

  return buildConfirmationCard(result, sessionType);
}

/**
 * Confirmation card shown after a successful lesson request.
 */
function buildConfirmationCard(apiResult, sessionType) {
  var success = apiResult && apiResult.success;

  var card = CardService.newCardBuilder()
    .setName("confirmation")
    .setHeader(
      CardService.newCardHeader()
        .setTitle(success ? "✅ Lesson Requested!" : "⚠️ Something went wrong")
        .setSubtitle("MyTutor")
        .setImageUrl(CONFIG.LOGO_URL)
        .setImageStyle(CardService.ImageStyle.CIRCLE)
    );

  var section = CardService.newCardSection();

  if (success) {
    section.addWidget(
      CardService.newTextParagraph().setText(
        "<b>Your lesson request has been sent to MyTutor!</b>"
      )
    );

    if (apiResult.lessonId) {
      section.addWidget(
        CardService.newKeyValue()
          .setTopLabel("Lesson ID")
          .setContent(apiResult.lessonId)
      );
    }

    if (apiResult.scheduledAt) {
      section.addWidget(
        CardService.newKeyValue()
          .setTopLabel("Scheduled Time")
          .setContent(apiResult.scheduledAt)
      );
    }

    if (apiResult.tutorName) {
      section.addWidget(
        CardService.newKeyValue()
          .setTopLabel("Your Tutor")
          .setContent(apiResult.tutorName)
      );
    }

    var msgText = sessionType === "live"
      ? "You'll receive a calendar invite and video link shortly. " +
        "Check your Gmail for confirmation."
      : "A tutor will respond to your request with a personalised explanation. " +
        "Check your MyTutor dashboard for updates.";

    section.addWidget(CardService.newTextParagraph().setText(msgText));

    if (apiResult.dashboardUrl) {
      section.addWidget(
        CardService.newButtonSet().addButton(
          CardService.newTextButton()
            .setText("Open MyTutor Dashboard")
            .setOpenLink(
              CardService.newOpenLink().setUrl(apiResult.dashboardUrl)
            )
        )
      );
    }
  } else {
    var errMsg = apiResult && apiResult.error
      ? apiResult.error
      : "Unable to reach MyTutor. Please try again or visit mytutor.com directly.";

    section.addWidget(CardService.newTextParagraph().setText(errMsg));

    section.addWidget(
      CardService.newButtonSet().addButton(
        CardService.newTextButton()
          .setText("Try Again")
          .setOnClickAction(
            CardService.newAction().setFunctionName("onClassroomAddonTrigger")
          )
      )
    );
  }

  card.addSection(section);
  return CardService.newNavigation().pushCard(card.build());
}

// ── CLASSROOM API HELPERS ────────────────────────────────────

/**
 * Fetches course work details from the Classroom API.
 * Falls back to safe defaults when IDs are missing.
 */
function fetchClassroomActivity(courseId, courseWorkId) {
  if (!courseId || !courseWorkId) {
    return { title: "General Help Request", description: "", courseName: "", subject: "" };
  }

  try {
    var course     = Classroom.Courses.get(courseId);
    var courseWork = Classroom.Courses.CourseWork.get(courseId, courseWorkId);

    var dueStr = "";
    if (courseWork.dueDate) {
      var d = courseWork.dueDate;
      dueStr = (d.month + "/" + d.day + "/" + d.year);
      if (courseWork.dueTime) {
        var t = courseWork.dueTime;
        dueStr += " " + (t.hours || 0) + ":" + String(t.minutes || 0).padStart(2, "0");
      }
    }

    // Collect material links/titles
    var materials = [];
    if (courseWork.materials) {
      courseWork.materials.forEach(function(mat) {
        if (mat.driveFile)      materials.push({ type: "Drive",    title: mat.driveFile.driveFile.title,  url: mat.driveFile.driveFile.alternateLink });
        else if (mat.youtubeVideo) materials.push({ type: "YouTube", title: mat.youtubeVideo.title, url: mat.youtubeVideo.alternateLink });
        else if (mat.link)      materials.push({ type: "Link",     title: mat.link.title, url: mat.link.url });
        else if (mat.form)      materials.push({ type: "Form",     title: mat.form.title, url: mat.form.formUrl });
      });
    }

    return {
      courseId:    courseId,
      courseWorkId: courseWorkId,
      courseName:  course.name,
      section:     course.section || "",
      subject:     course.name,         // Classroom doesn't expose subject natively
      title:       courseWork.title,
      description: courseWork.description || "",
      dueDate:     dueStr,
      maxPoints:   courseWork.maxPoints || null,
      workType:    courseWork.workType,
      materials:   materials,
    };
  } catch (err) {
    Logger.log("Classroom API error: " + err.message);
    return {
      title:       "Assignment",
      description: "",
      courseName:  "",
      subject:     "",
      error:       err.message,
    };
  }
}

/**
 * Fetches basic student profile info (name) from the Classroom API.
 */
function fetchStudentProfile(courseId, userId) {
  try {
    if (!courseId || !userId) return { name: Session.getActiveUser().getEmail() };
    var profile = Classroom.Courses.Students.get(courseId, userId);
    return {
      id:    userId,
      name:  profile.profile.name.fullName,
      email: profile.profile.emailAddress,
    };
  } catch (err) {
    return { name: Session.getActiveUser().getEmail() };
  }
}

// ── MYTUTOR API HELPER ───────────────────────────────────────

/**
 * Makes a POST request to the MyTutor API.
 *
 * @param {string} endpoint  e.g. "/lessons/create"
 * @param {Object} payload   JSON-serialisable request body
 * @returns {Object}         Parsed JSON response (or error object)
 */
function callMyTutorApi(endpoint, payload) {
  if (!CONFIG.MYTUTOR_API_KEY) {
    // In development / demo mode — return a mock response
    return mockMyTutorResponse(payload);
  }

  try {
    var response = UrlFetchApp.fetch(CONFIG.MYTUTOR_API_BASE + endpoint, {
      method:      "post",
      contentType: "application/json",
      headers: {
        "Authorization": "Bearer " + CONFIG.MYTUTOR_API_KEY,
        "X-Source":      "google-classroom-addon",
      },
      payload:          JSON.stringify(payload),
      muteHttpExceptions: true,
    });

    var code = response.getResponseCode();
    var body = JSON.parse(response.getContentText());

    if (code >= 200 && code < 300) {
      return Object.assign({ success: true }, body);
    } else {
      Logger.log("MyTutor API error " + code + ": " + response.getContentText());
      return { success: false, error: body.message || "API error " + code };
    }
  } catch (err) {
    Logger.log("MyTutor fetch error: " + err.message);
    return { success: false, error: err.message };
  }
}

/**
 * Mock API response used when no API key is configured.
 * Replace with real API integration once keys are set.
 */
function mockMyTutorResponse(payload) {
  var sessionTypes = { live: "Live Session", async: "Async Explanation", practice: "Practice Pack" };
  var urgencyTimes = { high: "Within 1 hour", medium: "Within 24 hours", low: "Within 3 days" };

  var lessonId = "MT-" + Math.random().toString(36).substring(2, 10).toUpperCase();
  var subject  = payload.classroom && payload.classroom.subject ? payload.classroom.subject : "your subject";

  return {
    success:       true,
    lessonId:      lessonId,
    tutorName:     "Sarah T.",      // will be matched dynamically by real API
    scheduledAt:   urgencyTimes[payload.request.urgency] || "Soon",
    sessionType:   sessionTypes[payload.request.sessionType] || "Live Session",
    lessonTopics:  ["Key concepts in " + subject, payload.request.helpTopic || "Coursework support"],
    dashboardUrl:  "https://www.mytutor.co.uk/students/dashboard",
    message:       "A tutor specialising in " + subject + " will reach out shortly!",
  };
}

// ── ADMIN / SETUP ────────────────────────────────────────────

/**
 * Run this function ONCE from the Apps Script editor to store
 * your MyTutor API key securely as a script property.
 *
 * Replace "YOUR_KEY_HERE" then run setApiKey().
 */
function setApiKey() {
  PropertiesService.getScriptProperties()
    .setProperty("MYTUTOR_API_KEY", "YOUR_KEY_HERE");
  Logger.log("✅ MyTutor API key saved.");
}
