{{-- Gestor do dojo edita o aluno no próprio dojo; quem edita pessoas na associação usa o cadastro completo. --}}
@if($canEditStudents && ! $student->is_teacher)<x-edit-link :href="route('operation.students.person.edit', [$dojo, $student])" label="Editar dados do aluno" />
@elseif($canEditPeople)<x-edit-link :href="route('associations.people.edit', [$dojo->association_id, $student->person_id, 'voltar' => request()->getRequestUri()])" label="Editar dados do aluno" />@endif
