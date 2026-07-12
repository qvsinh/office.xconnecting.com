# Hướng dẫn sử dụng Subagents — proxyv2.services.vn (rebuild)

Tài liệu này dành cho **người dùng (developer)**. Mô tả 5 subagent đã được cấu hình cho dự án CodeIgniter 4 này (bản rebuild của `proxy.services.vn` trên CI4 mới nhất + TailAdmin/Tailwind) và cách sử dụng chúng hiệu quả trong Claude Code.

---

## 1. Danh sách subagents

| Tên | Model | Nhiệm vụ | Phạm vi file |
|-----|-------|----------|--------------|
| **planner** | opus | Phân tích yêu cầu, lên kế hoạch chi tiết | Ghi `tasks/{id}/plan.md` |
| **backend-coder** | sonnet | Viết code PHP backend | `app/Controllers`, `app/Models`, `app/Libraries`, `app/Filters`, `app/Helpers`, `app/Database`, `app/Config`, `app/Commands`, `tests/` |
| **frontend-coder** | sonnet | Viết code giao diện | **Chỉ** `app/Views/` |
| **reviewer** | opus | Review code của 2 coder | Chỉ đọc, không sửa |
| **tester** | sonnet | Chạy unit test + browser test | `tests/` và browser |

> **Ranh giới cứng:** `backend-coder` KHÔNG được động vào `app/Views`. `frontend-coder` KHÔNG được động vào controller/model/library. `reviewer` sẽ chặn nếu vi phạm.

---

## 2. Cách gọi subagent

### Cách 1 — Gọi trực tiếp bằng tên (khuyến nghị)

Trong prompt của bạn, nói rõ tên agent:

```
@planner hãy đọc task tasks/2001 và lên plan
```

```
@backend-coder thêm endpoint POST /user/api-keys/{id}/toggle theo plan tại tasks/2001/plan.md
```

```
@frontend-coder tạo view app/Views/admin/api-access-keys/index.php (bảng danh sách API key, TailAdmin table)
```

```
@reviewer review tất cả thay đổi trên branch hiện tại
```

```
@tester chạy unit test cho AdminFilterAclTest và kiểm tra browser flow tạo API key + gọi console CNR
```

### Cách 2 — Để Claude tự chọn

Chỉ cần mô tả công việc, Claude sẽ tự nhận diện agent phù hợp dựa trên `description` của từng agent. Ví dụ:

- "Lên kế hoạch implement tính năng X" → `planner`
- "Viết controller cho ..." → `backend-coder`
- "Sửa giao diện trang ..." → `frontend-coder`
- "Review code vừa viết" → `reviewer`
- "Chạy test" hoặc "kiểm tra trên browser" → `tester`

### Cách 3 — Chạy song song nhiều agent

Khi backend và frontend độc lập, có thể yêu cầu Claude chạy 2 agent **song song**:

```
Chạy song song:
1. backend-coder: thêm endpoint xoá bản ghi API history
2. frontend-coder: thêm nút Delete + modal confirm (Alpine) trong view api-history
```

Claude sẽ phát đồng thời cả 2 tool call thay vì làm tuần tự → nhanh hơn đáng kể.

---

## 3. Luồng làm việc đề xuất

```
   planner
      │
      ▼
   tasks/{id}/plan.md
      │
      ├──────────────┐
      ▼              ▼
backend-coder   frontend-coder    (chạy song song khi độc lập)
      │              │
      └──────┬───────┘
             ▼
         reviewer
             │
       ┌─────┴─────┐
       │ có lỗi?   │
       ├─ Có ──────┴──► quay lại coder tương ứng
       │ Không
       ▼
        tester
             │
       ┌─────┴─────┐
       │ pass?     │
       ├─ Không ───┴──► coder fix
       │ Pass
       ▼
        git commit (bạn tự làm hoặc yêu cầu)
```

### Ví dụ một phiên làm việc đầy đủ

```
Bạn: "Có task 2001 trong tasks/2001/, hãy lên plan và implement"

→ Claude gọi planner → tạo tasks/2001/plan.md
→ Bạn xem plan, OK
→ Bạn: "ok plan đẹp, implement đi"
→ Claude gọi backend-coder + frontend-coder song song
→ Cả 2 báo cáo xong
→ Bạn: "review giúp"
→ Claude gọi reviewer
→ reviewer báo HIGH: 1 chỗ dùng jQuery thay vì fetch/Alpine
→ Bạn: "fix"
→ Claude gọi frontend-coder fix
→ Bạn: "test"
→ Claude gọi tester
→ tester báo ALL GREEN
→ Bạn: "commit"
```

---

## 4. Quy ước quan trọng (frontend-coder bắt buộc)

Để UI/UX nhất quán, `frontend-coder` được cấu hình tuân thủ:

| Yêu cầu | Sai | Đúng |
|---------|-----|------|
| Chỉ dùng Tailwind/TailAdmin | class Bootstrap/tabler, `style="..."` | class utility Tailwind |
| Tương tác dùng Alpine, không jQuery | `$('#x').on('click', ...)` | `x-data` / `@click` (Alpine) |
| AJAX dùng fetch | `$.ajax({...})` | `await fetch(url, {...})` + CSRF |
| Thông báo | `alert('Saved')` | `$dispatch('toast', {type:'success', text:'Saved'})` |
| Confirm trước hành động | `confirm('Sure?')` | component confirm Alpine dùng chung |
| Escape output | `<?= $user->name ?>` | `<?= esc($user->name) ?>` |

`reviewer` sẽ chặn merge nếu phát hiện vi phạm 1 trong các quy tắc trên.

---

## 5. Quản lý plan

- Có **task number** (ví dụ task 2001): plan lưu tại `tasks/2001/plan.md`
- **Không có** task number: plan lưu tại `tasks/YYYYMMDD/plan.md` theo ngày hiện tại (ví dụ `tasks/20260526/plan.md`)
- Sau khi plan xong, bạn nên **đọc lại** trước khi cho implement — sửa scope ở giai đoạn này rẻ hơn nhiều so với sửa sau khi đã code

---

## 6. Tips sử dụng

### Tip 1 — Luôn bắt đầu bằng `planner` cho task lớn
Task chạm > 3 file hoặc cần thiết kế DB: bắt buộc qua `planner` trước. Tiết kiệm thời gian gấp 3 lần so với "code thẳng".

### Tip 2 — Cho coder đọc plan
Khi gọi coder, ghi rõ `theo plan tại tasks/{id}/plan.md` để agent đọc plan thay vì hỏi lại bạn.

### Tip 3 — Review trước commit, luôn luôn
`reviewer` rẻ hơn rất nhiều so với việc revert/hotfix sau khi merge. Đừng bỏ qua.

### Tip 4 — Browser test cho mọi thay đổi View
View thay đổi mà không có browser test → khả năng cao có regression không phát hiện. Yêu cầu `tester` browser-test golden path + 1 edge case.

### Tip 5 — Đừng giao quá nhiều việc cho 1 lần gọi
Mỗi lần gọi 1 agent, giới hạn 1–3 file thay đổi. Task lớn nên chia thành nhiều phase (planner tự chia trong plan).

### Tip 6 — CodeGraph index dần theo code mới
Dự án rebuild bắt đầu từ trống, CodeGraph index dần khi code được viết. Giai đoạn đầu, dùng repo cũ `../proxy.services.vn` (đã index) làm tài liệu tham khảo. Trước khi sửa hàm/class có sẵn, bảo agent chạy `codegraph_impact` để biết blast radius.

---

## 7. Khi nào KHÔNG dùng subagent

- Task quá nhỏ (sửa 1 dòng, đổi tên biến): làm trực tiếp, không cần delegate
- Cần hỏi/làm rõ yêu cầu: trao đổi trực tiếp với Claude, đừng gọi agent
- Khám phá code để hiểu: hỏi Claude trực tiếp, hoặc dùng `Explore` agent

---

## 8. Cấu trúc file agent

Mỗi file `.md` trong thư mục này là 1 subagent. Format:

```markdown
---
name: agent-name              # tên gọi (không có khoảng trắng)
description: ...              # Claude dùng để chọn agent phù hợp
tools: Read, Write, Edit, ... # tool được phép dùng (tuỳ chọn)
model: opus | sonnet | haiku  # model dùng (tuỳ chọn)
---

System prompt cho agent...
```

Muốn chỉnh hành vi 1 agent: chỉnh file `.md` tương ứng. Thay đổi có hiệu lực ngay phiên Claude Code tiếp theo (không cần restart).

---

## 9. Troubleshooting

| Triệu chứng | Nguyên nhân thường gặp | Xử lý |
|-------------|----------------------|------|
| Agent không xuất hiện khi gọi | File `.md` sai frontmatter (thiếu `name` hoặc `description`) | Mở file kiểm tra YAML header |
| `backend-coder` tự sửa view | Description không đủ rõ về boundary | Kiểm tra phần "Boundaries — hard rules" trong file |
| `reviewer` luôn approve | Agent đang dùng model haiku/sonnet thay vì opus | Đổi `model: opus` trong frontmatter |
| `tester` không chạy được PHPUnit | Vendor chưa cài | Chạy `composer install` |

---

## 10. Mở rộng

Có thể tạo thêm agent chuyên biệt khi cần, ví dụ:
- `migration-writer` — chuyên viết migration phức tạp
- `cli-coder` — chuyên viết spark commands
- `api-doc-writer` — chuyên tạo tài liệu API

Mẫu: copy 1 file `.md` hiện có, đổi `name` + `description` + nội dung system prompt.
