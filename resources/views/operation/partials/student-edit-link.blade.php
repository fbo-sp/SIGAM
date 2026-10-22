{{-- Gestor do dojo edita o aluno no próprio dojo; quem edita pessoas na associação usa o cadastro completo. --}}
@php($label = 'Editar dados de '.($student->person->social_name ?: $student->person->full_name))
@if($canEditStudents && ! $student->is_teacher)<x-edit-link :href="route('operation.students.person.edit', [$dojo, $student])" :label="$label" />
@elseif($canEditPeople)<x-edit-link :href="route('associations.people.edit', [$dojo->association_id, $student->person_id, 'voltar' => request()->getRequestUri()])" :label="$label" />@endif
