document.addEventListener("DOMContentLoaded", () => {
    const form = document.getElementById("uploadForm");
    const fileInput = document.getElementById("fileInput");
    const dropzone = document.getElementById("dropzone");
    const statusEl = document.getElementById("uploadStatus");
    const grid = document.getElementById("fileGrid");
    const searchInput = document.getElementById("searchInput");
    const detailPane = document.getElementById("detailPane");
    const detailBody = detailPane?.querySelector(".detail-body");
    const detailEmpty = detailPane?.querySelector(".detail-empty");
    const detailName = document.getElementById("detailName");
    const detailType = document.getElementById("detailType");
    const detailSize = document.getElementById("detailSize");
    const detailDate = document.getElementById("detailDate");
    const detailActions = document.getElementById("detailActions");
    const currentPath = form?.dataset.path || "";

    const setStatus = (message, isError = false) => {
        if (!statusEl) return;
        statusEl.textContent = message || "";
        statusEl.classList.toggle("error", Boolean(message) && isError);
    };

    const setUploading = (state) => {
        if (!form) return;
        form.querySelectorAll("button").forEach((btn) => (btn.disabled = state));
        if (state) setStatus("กำลังอัปโหลด...", false);
    };

    const buildFormData = (entries) => {
        const formData = new FormData();
        formData.append("ajaxUpload", "1");
        formData.append("path", currentPath);
        entries.forEach(({ file, relativePath }) => {
            formData.append("files[]", file);
            formData.append("paths[]", relativePath || file.name);
        });
        return formData;
    };

    const uploadEntries = async (entries) => {
        if (!entries || !entries.length || !form) {
            setStatus("ไม่ได้เลือกไฟล์หรือโฟลเดอร์", true);
            return;
        }

        setUploading(true);
        try {
            const response = await fetch(form.action, {
                method: "POST",
                body: buildFormData(entries),
            });
            const data = await response.json();
            if (data.success) {
                location.reload();
            } else {
                setStatus(`อัปโหลดไม่สำเร็จบางไฟล์: ${(data.failed || []).join(", ")}`, true);
            }
        } catch (err) {
            setStatus(`อัปโหลดล้มเหลว: ${err.message}`, true);
        } finally {
            setUploading(false);
        }
    };

    const collectFromInput = () =>
        Array.from(fileInput?.files || []).map((file) => ({
            file,
            relativePath: file.webkitRelativePath || file.name,
        }));

    const readAllDirectoryEntries = (reader) =>
        new Promise((resolve) => {
            const entries = [];
            const readEntries = () => {
                reader.readEntries((batch) => {
                    if (!batch.length) {
                        resolve(entries);
                        return;
                    }
                    entries.push(...batch);
                    readEntries();
                });
            };
            readEntries();
        });

    const traverseEntry = async (entry, pathPrefix = "") => {
        if (entry.isFile) {
            return new Promise((resolve) => {
                entry.file((file) => resolve([{ file, relativePath: pathPrefix + file.name }]));
            });
        }

        if (entry.isDirectory) {
            const reader = entry.createReader();
            const childEntries = await readAllDirectoryEntries(reader);
            const files = [];
            for (const child of childEntries) {
                const nested = await traverseEntry(child, pathPrefix + entry.name + "/");
                files.push(...nested);
            }
            return files;
        }

        return [];
    };

    const collectFromDataTransfer = async (dataTransfer) => {
        const items = Array.from(dataTransfer.items || []);
        if (items.length && items[0].webkitGetAsEntry) {
            const files = [];
            for (const item of items) {
                const entry = item.webkitGetAsEntry();
                if (entry) {
                    const nested = await traverseEntry(entry);
                    files.push(...nested);
                }
            }
            return files;
        }

        return Array.from(dataTransfer.files || []).map((file) => ({
            file,
            relativePath: file.name,
        }));
    };

    if (form && fileInput) {
        form.addEventListener("submit", (e) => {
            e.preventDefault();
            uploadEntries(collectFromInput());
        });

        fileInput.addEventListener("change", () => {
            uploadEntries(collectFromInput());
        });
    }

    if (dropzone) {
        const setDragState = (active) => dropzone.classList.toggle("dragover", active);
        ["dragenter", "dragover"].forEach((eventName) => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                setDragState(true);
            });
        });
        ["dragleave", "dragend", "drop"].forEach((eventName) => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                setDragState(false);
            });
        });
        dropzone.addEventListener("drop", async (e) => {
            const entries = await collectFromDataTransfer(e.dataTransfer);
            uploadEntries(entries);
        });
    }

    // Card selection + details
    const cards = Array.from(document.querySelectorAll(".file-card"));
    const clearSelection = () => cards.forEach((c) => c.classList.remove("selected"));

    const renderDetail = (card) => {
        if (!detailPane || !detailBody || !detailEmpty || !detailActions) return;
        detailEmpty.style.display = "none";
        detailBody.classList.remove("hidden");
        detailName.textContent = card.dataset.name || "-";
        detailType.textContent = card.dataset.type || "-";
        detailSize.textContent = card.dataset.size || "-";
        detailDate.textContent = card.dataset.date || "-";

        detailActions.innerHTML = "";
        const kind = card.dataset.kind;
        const path = card.dataset.path;
        const download = card.dataset.download;

        const makeBtn = (href, text, danger = false) => {
            const a = document.createElement("a");
            a.href = href;
            a.textContent = text;
            a.className = `pill${danger ? " danger" : ""}`;
            a.addEventListener("click", (e) => e.stopPropagation());
            return a;
        };

        if (kind === "folder") {
            detailActions.appendChild(makeBtn(`?path=${encodeURIComponent(path)}`, "เปิด"));
        } else {
            detailActions.appendChild(makeBtn(`?path=${encodeURIComponent(currentPath)}&download=${encodeURIComponent(download)}`, "ดาวน์โหลด"));
        }
        detailActions.appendChild(
            makeBtn(`?path=${encodeURIComponent(currentPath)}&delete=${encodeURIComponent(card.dataset.name)}`, "ลบ", true)
        );
    };

    cards.forEach((card) => {
        card.addEventListener("click", () => {
            clearSelection();
            card.classList.add("selected");
            renderDetail(card);
        });
        card.querySelectorAll("a").forEach((link) => {
            link.addEventListener("click", (e) => e.stopPropagation());
        });
    });

    // Search filter
    if (searchInput && grid) {
        searchInput.addEventListener("input", (e) => {
            const q = e.target.value.toLowerCase();
            cards.forEach((card) => {
                const name = (card.dataset.name || "").toLowerCase();
                const match = name.includes(q);
                card.style.display = match ? "" : "none";
            });
        });
    }
});
