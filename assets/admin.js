(function () {
  const settings = window.SafeMigrateAdmin;

  if (!settings) {
    return;
  }

  const formatBytes = (bytes) => {
    if (!Number.isFinite(bytes) || bytes <= 0) {
      return "0 B";
    }

    const units = ["B", "KB", "MB", "GB", "TB"];
    let value = bytes;
    let index = 0;

    while (value >= 1024 && index < units.length - 1) {
      value /= 1024;
      index += 1;
    }

    return `${value.toFixed(value >= 10 || index === 0 ? 0 : 1)} ${units[index]}`;
  };

  const escapeHtml = (value) =>
    String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#39;");

  const setDeep = (target, key, value) => {
    const segments = key.split(".");
    let current = target;

    segments.forEach((segment, index) => {
      if (index === segments.length - 1) {
        current[segment] = value;
        return;
      }

      current[segment] ||= {};
      current = current[segment];
    });
  };

  const collectPayload = (root) =>
    Array.from(root.querySelectorAll("[data-payload-key]")).reduce((payload, field) => {
      const key = field.dataset.payloadKey;

      if (!key) {
        return payload;
      }

      let value;

      if (field.type === "checkbox") {
        value = field.checked;
      } else if (field.tagName === "TEXTAREA") {
        value = field.value
          .split(/\r?\n/)
          .map((entry) => entry.trim())
          .filter(Boolean);
      } else if (field.type === "number") {
        value = Number(field.value || 0);
      } else if (field.value === "true" || field.value === "false") {
        value = field.value === "true";
      } else {
        value = field.value;
      }

      setDeep(payload, key, value);
      return payload;
    }, {});

  const renderList = (items, status = "pass", label = "Detail") =>
    (items || [])
      .map(
        (item) => `
          <li class="safe-migrate-check safe-migrate-check--${status}">
            <strong>${label}</strong>
            <span>${item}</span>
          </li>
        `
      )
      .join("");

  const renderers = {
    preflight: (payload) => `
      <div class="safe-migrate-score">
        <span class="safe-migrate-score__value">${payload.report.health_score}</span>
        <span class="safe-migrate-score__label">Health score</span>
      </div>
      <ul class="safe-migrate-checks">
        ${payload.report.checks.map((check) => `
          <li class="safe-migrate-check safe-migrate-check--${check.status}">
            <strong>${check.label}</strong>
            <span>${check.message}</span>
          </li>
        `).join("")}
        ${renderList(payload.report.builder_warnings, "warn", "Builder warning")}
      </ul>
    `,
    "export-plan": (payload) => `
      <div class="safe-migrate-summary-grid">
        <div class="safe-migrate-stat"><strong>${payload.plan.total_files}</strong><span>files</span></div>
        <div class="safe-migrate-stat"><strong>${formatBytes(payload.plan.total_bytes)}</strong><span>planned bytes</span></div>
        <div class="safe-migrate-stat"><strong>${payload.plan.chunk_count}</strong><span>chunks</span></div>
        <div class="safe-migrate-stat"><strong>${payload.plan.database_tables}</strong><span>DB tables</span></div>
      </div>
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Manifest</strong><span>${payload.plan.manifest_path}</span></li>
        ${renderList(payload.plan.builder_warnings, "warn", "Builder warning")}
      </ul>
    `,
    export: (payload) => `
      <div class="safe-migrate-summary-grid">
        <div class="safe-migrate-stat"><strong>${payload.export.total_files}</strong><span>files</span></div>
        <div class="safe-migrate-stat"><strong>${formatBytes(payload.export.total_bytes)}</strong><span>filesystem bytes</span></div>
        <div class="safe-migrate-stat"><strong>${payload.export.file_chunk_count}</strong><span>file chunks</span></div>
        <div class="safe-migrate-stat"><strong>${payload.export.database_segment_count}</strong><span>DB segments</span></div>
      </div>
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Artifacts</strong><span>${payload.export.artifact_directory}</span></li>
        ${renderList(payload.export.compatibility?.builder_warnings, "warn", "Builder warning")}
      </ul>
    `,
    validation: (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--${payload.validation.status === "valid" ? "pass" : "fail"}"><strong>Validation</strong><span>${payload.validation.status}</span></li>
        ${renderList(payload.validation.issues, "fail", "Issue")}
        ${renderList(payload.validation.builder_warnings, "warn", "Builder warning")}
      </ul>
    `,
    preview: (payload) => `
      <div class="safe-migrate-summary-grid">
        <div class="safe-migrate-stat"><strong>${payload.preview.filesystem_chunks}</strong><span>filesystem chunks</span></div>
        <div class="safe-migrate-stat"><strong>${payload.preview.database_segments}</strong><span>staged DB segments</span></div>
      </div>
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Workspace</strong><span>${payload.preview.workspace.base}</span></li>
        ${Object.entries(payload.preview.remap_rules || {}).map(([from, to]) => `
          <li class="safe-migrate-check safe-migrate-check--pass"><strong>Remap</strong><span>${from} -> ${to}</span></li>
        `).join("")}
        ${renderList(payload.preview.builder_warnings, "warn", "Builder warning")}
      </ul>
    `,
    "restore-execute": (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Status</strong><span>${payload.restore.status}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Snapshot</strong><span>${payload.restore.snapshot_artifact_directory}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Workspace</strong><span>${payload.restore.workspace.base}</span></li>
      </ul>
    `,
    rollback: (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Status</strong><span>${payload.rollback.status}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Snapshot</strong><span>${payload.rollback.snapshot_artifact_directory}</span></li>
      </ul>
    `,
    resume: (payload) => `
      <ul class="safe-migrate-checks">
        ${payload.restore ? `
          <li class="safe-migrate-check safe-migrate-check--pass"><strong>Status</strong><span>${payload.restore.status}</span></li>
          <li class="safe-migrate-check safe-migrate-check--pass"><strong>Workspace</strong><span>${payload.restore.workspace.base}</span></li>
        ` : `
          <li class="safe-migrate-check safe-migrate-check--pass"><strong>Status</strong><span>${payload.push_pull.validation.status}</span></li>
          <li class="safe-migrate-check safe-migrate-check--pass"><strong>Artifact directory</strong><span>${payload.push_pull.artifact_directory}</span></li>
          <li class="safe-migrate-check safe-migrate-check--pass"><strong>Transfer stage</strong><span>${payload.push_pull.transfer_progress?.stage || "completed"}</span></li>
        `}
      </ul>
    `,
    cleanup: (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Retention exports</strong><span>${payload.cleanup.retention.exports}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Retention restores</strong><span>${payload.cleanup.retention.restores}</span></li>
        ${renderList(payload.cleanup.exports, "pass", "Removed export")}
        ${renderList(payload.cleanup.restores, "pass", "Removed restore")}
      </ul>
    `,
    "transfer-token": (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Token</strong><span class="safe-migrate-inline-code">${payload.transfer_token.token}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Expires</strong><span>${payload.transfer_token.expires_at}</span></li>
      </ul>
    `,
    "push-pull": (payload) => `
      <div class="safe-migrate-summary-grid">
        <div class="safe-migrate-stat"><strong>${payload.push_pull.validation.status}</strong><span>package status</span></div>
        <div class="safe-migrate-stat"><strong>${payload.push_pull.remote_export.total_files || 0}</strong><span>remote files</span></div>
        <div class="safe-migrate-stat"><strong>${payload.push_pull.remote_export.file_chunk_count || 0}</strong><span>file chunks</span></div>
        <div class="safe-migrate-stat"><strong>${payload.push_pull.remote_export.database_segment_count || 0}</strong><span>DB segments</span></div>
      </div>
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Artifact directory</strong><span>${payload.push_pull.artifact_directory}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Source site</strong><span>${payload.push_pull.source_url}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Remote export job</strong><span>${payload.push_pull.remote_export_job_id}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Transfer stage</strong><span>${payload.push_pull.transfer_progress?.stage || "completed"}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Downloaded chunks</strong><span>${payload.push_pull.transfer_progress?.downloaded_chunks || 0} / ${payload.push_pull.transfer_progress?.total_chunks || 0}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Downloaded tables</strong><span>${payload.push_pull.transfer_progress?.downloaded_tables || 0} / ${payload.push_pull.transfer_progress?.total_tables || 0}</span></li>
        ${renderList(payload.push_pull.remote_preflight.builder_warnings, "warn", "Remote builder warning")}
      </ul>
    `,
    "support-bundle": (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Path</strong><span>${payload.support_bundle.path}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Checkpoints</strong><span>${payload.support_bundle.checkpoint_count}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Logs</strong><span>${payload.support_bundle.log_count}</span></li>
      </ul>
    `,
    license: (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Tier</strong><span>${payload.license.tier}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Status</strong><span>${payload.license.status}</span></li>
        <li class="safe-migrate-check safe-migrate-check--${payload.feature_policy.is_pro ? "pass" : "warn"}"><strong>Feature policy</strong><span>${payload.feature_policy.is_pro ? "Pro unlocked" : "Core mode"}</span></li>
      </ul>
    `,
    settings: (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Retain exports</strong><span>${payload.settings.cleanup.retain_exports}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Retain restores</strong><span>${payload.settings.cleanup.retain_restores}</span></li>
        <li class="safe-migrate-check safe-migrate-check--${payload.feature_policy.is_pro ? "pass" : "warn"}"><strong>Mode</strong><span>${payload.feature_policy.is_pro ? "Custom policies active" : "Core defaults active"}</span></li>
      </ul>
    `,
    "failure-injection": (payload) => `
      <ul class="safe-migrate-checks">
        <li class="safe-migrate-check safe-migrate-check--${payload.available ? "warn" : "fail"}"><strong>Available</strong><span>${payload.available}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Stage</strong><span>${payload.failure_injection.stage || "off"}</span></li>
        <li class="safe-migrate-check safe-migrate-check--pass"><strong>Enabled</strong><span>${payload.failure_injection.enabled}</span></li>
      </ul>
    `,
  };

  const applyArtifactDirectory = (artifactDirectory) => {
    if (!artifactDirectory) {
      return;
    }

    document
      .querySelectorAll('[data-payload-key="artifact_directory"]')
      .forEach((field) => {
        field.value = artifactDirectory;
      });
  };

  const renderJobSummary = (job) => {
    if (!job) {
      return "";
    }

    const summary = job.summary || {};
    const lines = [
      `<li class="safe-migrate-check safe-migrate-check--${job.status === "completed" || job.status === "rolled_back" ? "pass" : job.status === "failed" || job.status === "rollback_failed" ? "fail" : "warn"}"><strong>Job</strong><span>#${job.id} ${escapeHtml(job.type)} (${escapeHtml(job.status)})</span></li>`,
      `<li class="safe-migrate-check safe-migrate-check--pass"><strong>Progress</strong><span>${job.progress_percent}%</span></li>`,
      `<li class="safe-migrate-check safe-migrate-check--pass"><strong>Stage</strong><span>${escapeHtml(job.current_stage || summary.stage || "n/a")}</span></li>`,
    ];

    if (summary.artifact_directory) {
      lines.push(`<li class="safe-migrate-check safe-migrate-check--pass"><strong>Artifacts</strong><span>${escapeHtml(summary.artifact_directory)}</span></li>`);
    }

    if (summary.snapshot_artifact_directory) {
      lines.push(`<li class="safe-migrate-check safe-migrate-check--pass"><strong>Snapshot</strong><span>${escapeHtml(summary.snapshot_artifact_directory)}</span></li>`);
    }

    if (summary.remote_export_job_id) {
      lines.push(`<li class="safe-migrate-check safe-migrate-check--pass"><strong>Remote export</strong><span>${summary.remote_export_job_id}</span></li>`);
    }

    if (Number.isFinite(summary.total_chunks) && summary.total_chunks > 0) {
      lines.push(`<li class="safe-migrate-check safe-migrate-check--pass"><strong>Chunks</strong><span>${summary.downloaded_chunks || 0} / ${summary.total_chunks}</span></li>`);
    }

    if (Number.isFinite(summary.total_tables) && summary.total_tables > 0) {
      lines.push(`<li class="safe-migrate-check safe-migrate-check--pass"><strong>Tables</strong><span>${summary.downloaded_tables || 0} / ${summary.total_tables}</span></li>`);
    }

    return `<ul class="safe-migrate-checks">${lines.join("")}</ul>`;
  };

  const renderJobsTable = (jobs) =>
    (jobs || [])
      .map((job) => `
        <tr>
          <td>${job.id}</td>
          <td>${escapeHtml(job.type)}</td>
          <td>${escapeHtml(job.status)}</td>
          <td>${escapeHtml(job.current_stage || job.summary?.stage || "")}</td>
          <td>${job.progress_percent}%</td>
          <td>${escapeHtml(job.updated_at || "")}</td>
        </tr>
      `)
      .join("");

  const runRequest = async ({ endpoint, method = "POST", payload }) => {
    const response = await fetch(`${settings.baseUrl}${endpoint}`, {
      method,
      headers: {
        "X-WP-Nonce": settings.nonce,
        ...(payload !== undefined ? { "Content-Type": "application/json" } : {}),
      },
      ...(payload !== undefined ? { body: JSON.stringify(payload) } : {}),
    });

    const body = await response.json();

    if (!response.ok) {
      const code = body?.data?.safe_migrate_code ? ` [${body.data.safe_migrate_code}]` : "";
      throw new Error(`${body.message || "Request failed."}${code}`);
    }

    return body;
  };

  const fetchJson = async (endpoint) => {
    const response = await fetch(`${settings.baseUrl}${endpoint}`, {
      headers: {
        "X-WP-Nonce": settings.nonce,
      },
    });
    const body = await response.json();

    if (!response.ok) {
      throw new Error(body.message || "Request failed.");
    }

    return body;
  };

  const refreshRecentJobs = async () => {
    const tableBody = document.querySelector('[data-role="recent-jobs"]');

    if (!tableBody) {
      return;
    }

    try {
      const body = await fetchJson("/jobs?limit=10");
      tableBody.innerHTML = renderJobsTable(body.jobs || []);
    } catch (error) {
      // Ignore background refresh failures in the admin surface.
    }
  };

  const attachJobMonitor = () => {
    const root = document.querySelector("[data-safe-migrate-job-monitor]");

    if (!root) {
      return;
    }

    const input = root.querySelector("[data-job-monitor-id]");
    const button = root.querySelector("[data-safe-migrate-refresh-job]");
    const statusNode = root.querySelector('[data-role="status"]');
    const reportNode = root.querySelector('[data-role="report"]');

    if (!input || !button || !statusNode || !reportNode) {
      return;
    }

    let timer = 0;

    const stopPolling = () => {
      if (timer) {
        window.clearTimeout(timer);
        timer = 0;
      }
    };

    const schedulePolling = (job) => {
      stopPolling();

      if (job && job.status === "running") {
        timer = window.setTimeout(refresh, 5000);
      }
    };

    const refresh = async () => {
      const jobId = Number(input.value || settings.defaults?.trackedJobId || 0);

      if (!jobId) {
        statusNode.textContent = "Choose a job to monitor.";
        reportNode.innerHTML = "";
        stopPolling();
        return;
      }

      statusNode.textContent = `Loading job #${jobId}...`;

      try {
        const body = await fetchJson(`/jobs/${jobId}`);
        reportNode.innerHTML = renderJobSummary(body.job);
        statusNode.textContent = `Job #${jobId}: ${body.job.status} (${body.job.progress_percent}%).`;
        schedulePolling(body.job);
      } catch (error) {
        statusNode.textContent = error.message || "Could not load job.";
        stopPolling();
      }
    };

    button.addEventListener("click", refresh);
    input.addEventListener("change", refresh);

    if (Number(input.value || 0) > 0) {
      refresh();
    }
  };

  const attachRunner = (root, mode) => {
    const button = root.querySelector("button:not([data-safe-migrate-delete])");
    const statusNode = root.querySelector('[data-role="status"]');
    const reportNode = root.querySelector('[data-role="report"]');
    const endpoint = root.dataset.endpoint;
    const renderer = renderers[root.dataset.renderer];

    if (!button || !statusNode || !reportNode || !endpoint || !renderer) {
      return;
    }

    button.addEventListener("click", async () => {
      button.disabled = true;
      statusNode.textContent = "Running...";

      try {
        const payload = mode === "form" ? collectPayload(root) : undefined;
        const body = await runRequest({ endpoint, payload });
        reportNode.innerHTML = renderer(body);
        applyArtifactDirectory(body?.push_pull?.artifact_directory);
        if (body?.job?.id) {
          const monitorInput = document.querySelector("[data-job-monitor-id]");

          if (monitorInput) {
            monitorInput.value = body.job.id;
            monitorInput.dispatchEvent(new Event("change"));
          }
        }
        statusNode.textContent = body.job
          ? `Job #${body.job.id} finished with status: ${body.job.status}.`
          : "Saved.";
        refreshRecentJobs();
      } catch (error) {
        statusNode.textContent = error.message || "Request failed.";
      } finally {
        button.disabled = false;
      }
    });

    const deleteButton = root.querySelector("[data-safe-migrate-delete]");

    if (deleteButton) {
      deleteButton.addEventListener("click", async () => {
        deleteButton.disabled = true;
        statusNode.textContent = "Clearing...";

        try {
          const body = await runRequest({ endpoint: deleteButton.dataset.safeMigrateDelete, method: "DELETE" });
          reportNode.innerHTML = renderer(body);
          statusNode.textContent = "Cleared.";
        } catch (error) {
          statusNode.textContent = error.message || "Request failed.";
        } finally {
          deleteButton.disabled = false;
        }
      });
    }
  };

  document.querySelectorAll("[data-safe-migrate-runner]").forEach((root) => attachRunner(root, "action"));
  document.querySelectorAll("[data-safe-migrate-form-runner]").forEach((root) => attachRunner(root, "form"));
  attachJobMonitor();
  refreshRecentJobs();
  window.setInterval(refreshRecentJobs, 10000);
})();
