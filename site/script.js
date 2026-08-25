(() => {
  "use strict";

  const form = document.querySelector("#light-builder");
  const generatorOrigin = "https://g.statuslights.dev";

  if (!form) {
    return;
  }

  const elements = {
    owner: document.querySelector("#owner"),
    repository: document.querySelector("#repository"),
    workflow: document.querySelector("#workflow"),
    job: document.querySelector("#job"),
    text: document.querySelector("#label-text"),
    font: document.querySelector("#font"),
    size: document.querySelector("#size"),
    fontSize: document.querySelector("#font-size"),
    radius: document.querySelector("#radius"),
    successColor: document.querySelector("#success-color"),
    failureColor: document.querySelector("#failure-color"),
    runningColor: document.querySelector("#running-color"),
    unknownColor: document.querySelector("#unknown-color"),
    sizeOutput: document.querySelector("#size-output"),
    fontSizeOutput: document.querySelector("#font-size-output"),
    radiusOutput: document.querySelector("#radius-output"),
    svg: document.querySelector("#light-preview"),
    rect: document.querySelector("#preview-background"),
    previewText: document.querySelector("#preview-text"),
    previewTitle: document.querySelector("#preview-title"),
    generatedUrl: document.querySelector("#generated-url"),
    copyButton: document.querySelector("#copy-url"),
  };

  const fonts = {
    sans: "Arial, Helvetica, sans-serif",
    mono: "ui-monospace, SFMono-Regular, Consolas, monospace",
    serif: "Georgia, 'Times New Roman', serif",
  };

  const states = {
    success: { label: "Success", colorInput: elements.successColor },
    failure: { label: "Failure", colorInput: elements.failureColor },
    running: { label: "Running", colorInput: elements.runningColor },
    unknown: { label: "Unknown", colorInput: elements.unknownColor },
  };

  let selectedState = "success";

  const cleanSourceSegment = (value, fallback) => {
    const cleaned = value.trim().replace(/[^A-Za-z0-9._-]/g, "");
    return cleaned || fallback;
  };

  const cleanJobName = (value) => value
    .trim()
    .replace(/[\u0000-\u001f\u007f]/g, "")
    .slice(0, 100);

  const hex = (value) => value.replace("#", "").toLowerCase();

  const contrastColor = (value) => {
    const normalized = hex(value);
    const channels = [0, 2, 4].map((offset) => parseInt(normalized.slice(offset, offset + 2), 16) / 255);
    const linear = channels.map((channel) =>
      channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4,
    );
    const luminance = (0.2126 * linear[0]) + (0.7152 * linear[1]) + (0.0722 * linear[2]);
    const whiteContrast = 1.05 / (luminance + 0.05);
    const blackContrast = (luminance + 0.05) / 0.05;

    return whiteContrast >= blackContrast ? "#ffffff" : "#000000";
  };

  const encode = (value) => encodeURIComponent(value).replace(/%2F/gi, "%252F");

  const updatePreview = () => {
    const height = Number(elements.size.value);
    const fontSize = Math.min(Number(elements.fontSize.value), height - 2);
    const radius = Math.min(Number(elements.radius.value), Math.floor(height / 2));
    const template = elements.text.value.trim();
    const label = template.replaceAll("{status}", states[selectedState].label);
    const characterCount = [...label].length;
    const padding = Math.ceil(height * 0.28);
    const width = label
      ? Math.max(height, Math.ceil((characterCount * fontSize * 0.64) + (padding * 2)))
      : height;
    const background = states[selectedState].colorInput.value;
    const textColor = contrastColor(background);

    elements.sizeOutput.value = `${height}px`;
    elements.fontSizeOutput.value = `${fontSize}px`;
    elements.radiusOutput.value = `${radius}px`;
    elements.fontSize.max = String(Math.max(8, height - 2));
    elements.radius.max = String(Math.floor(height / 2));

    elements.svg.setAttribute("width", String(width));
    elements.svg.setAttribute("height", String(height));
    elements.svg.setAttribute("viewBox", `0 0 ${width} ${height}`);
    elements.rect.setAttribute("width", String(width));
    elements.rect.setAttribute("height", String(height));
    elements.rect.setAttribute("rx", String(radius));
    elements.rect.setAttribute("fill", background);
    elements.previewText.setAttribute("x", String(width / 2));
    elements.previewText.setAttribute("y", String(height / 2));
    elements.previewText.setAttribute("fill", textColor);
    elements.previewText.setAttribute("font-size", String(fontSize));
    elements.previewText.setAttribute("font-family", fonts[elements.font.value]);
    elements.previewText.textContent = label;
    const job = cleanJobName(elements.job.value);
    const previewTarget = label || job || "Workflow";
    elements.previewTitle.textContent = `${previewTarget} status: ${selectedState}`;

    const owner = cleanSourceSegment(elements.owner.value, "owner");
    const repository = cleanSourceSegment(elements.repository.value, "repository");
    const workflow = cleanSourceSegment(elements.workflow.value, "workflow.yml");
    const path = [
      "",
      "github",
      encode(owner),
      encode(repository),
      encode(workflow),
    ];

    if (job) {
      path.push("job", encode(job));
    }

    path.push(
      "size",
      height,
      "font",
      elements.font.value,
      "font-size",
      fontSize,
      "radius",
      radius,
      "success-color",
      hex(elements.successColor.value),
      "failure-color",
      hex(elements.failureColor.value),
      "running-color",
      hex(elements.runningColor.value),
      "unknown-color",
      hex(elements.unknownColor.value),
    );

    if (template) {
      path.push("text", `${encode(template)}.svg`);
    } else {
      path[path.length - 1] = `${path[path.length - 1]}.svg`;
    }

    elements.generatedUrl.textContent = `${generatorOrigin}${path.join("/")}`;
  };

  const copyUrl = async () => {
    const value = elements.generatedUrl.textContent;

    try {
      await navigator.clipboard.writeText(value);
    } catch {
      const textarea = document.createElement("textarea");
      textarea.value = value;
      textarea.style.position = "fixed";
      textarea.style.opacity = "0";
      document.body.append(textarea);
      textarea.select();
      document.execCommand("copy");
      textarea.remove();
    }

    elements.copyButton.textContent = "Copied";
    window.setTimeout(() => {
      elements.copyButton.textContent = "Copy";
    }, 1600);
  };

  form.addEventListener("input", updatePreview);
  form.addEventListener("change", updatePreview);
  elements.copyButton.addEventListener("click", copyUrl);

  document.querySelectorAll(".state-button").forEach((button) => {
    button.addEventListener("click", () => {
      selectedState = button.dataset.state;
      document.querySelectorAll(".state-button").forEach((candidate) => {
        candidate.classList.toggle("active", candidate === button);
      });
      updatePreview();
    });
  });

  updatePreview();
})();
