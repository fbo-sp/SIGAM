<span class="status-pill exam-status-{{ $exam->status }}">{{ \App\Models\RankExam::STATUSES[$exam->status] ?? $exam->status }}</span>
