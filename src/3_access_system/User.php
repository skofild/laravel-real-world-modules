<?php
class User
{
    public function accessibleUserIds()
    {
        // Получаем текущую роль пользователя и её иерархию
        $user_hierarchy = $this->access_role->access_level;

        // Если это роль с полными правами, возвращаем всех пользователей
        if (Gate::forUser($this)->allows('has-permission', 'full_access')) {
            return User::all()->pluck('id')->toArray();
        }

        // Если у пользователя есть доступ к нижестоящим
        if (Gate::forUser($this)->allows('has-permission', 'can_manage_lower_roles')) {
            return User::whereHas('access_role', function ($query) use ($user_hierarchy) {
                $query->where('access_level', '>', $user_hierarchy);
            })->pluck('id')->toArray();
        }


        // Получаем отделы, где пользователь назначен директором
        $director_departments = Department::where('director_id', $this->id)->pluck('id')->toArray();
        $leader_departments = Department::where('leader_id', $this->id)->pluck('id')->toArray();
        if (count($leader_departments) == 0) {
            $leader_departments = Department::where('deputy_leader_id', $this->id)->pluck('id')->toArray();
        }
        // Если у пользователя есть доступ к нижестоящим своего отдела + учитываем отделы, где он директор
        if (Gate::forUser($this)->allows('has-permission', 'can_manage_department_users')) {
            $query = User::where(function ($query) use ($director_departments, $leader_departments) {
                $query->whereIn('department_id', $director_departments)
                    ->orWhereIn('department_id', $leader_departments);
            });

            if (Gate::forUser($this)->allows('has-permission', 'can_manage_department_users')) {
                $query = User::where(function ($query) use ($director_departments, $leader_departments) {
                    $query->whereIn('department_id', $director_departments)
                        ->orWhereIn('department_id', $leader_departments);
                });

                // Для финансового отдела: добавляем бухгалтеров, даже если их роль выше
                if (Gate::forUser($this)->allows('access-section', 'finance_department_access')) {
                    $query->where(function ($subQuery) use ($user_hierarchy) {
                        $subQuery->whereHas('access_role', function ($q) use ($user_hierarchy) {
                            $q->where('access_level', '>', $user_hierarchy);
                        })->orWhereHas('access_role', function ($q) {
                            $q->whereIn('id', [AccessRole::ROLE_ACCOUNTANT]);
                        });
                    });
                } else {
                    $query->whereHas('access_role', function ($q) use ($user_hierarchy) {
                        $q->where('access_level', '>', $user_hierarchy);
                    });
                }

                return $query->pluck('id')->toArray();
            }

            return $query->pluck('id')->toArray();
        }
        $group_leader_ids = $this->getUnitsWhereUserIsLeader();
        // Если у пользователя есть доступ к нижестоящим своей группы
        if (Gate::forUser($this)->allows('has-permission', 'group_view_access')) {
            return User::whereIn('unit_id', $group_leader_ids)
                ->whereHas('access_role', function ($query) use ($user_hierarchy) {
                    $query->where('access_level', '>', $user_hierarchy);
                })->pluck('id')->toArray();
        }

        // В других случаях возвращаем только собственный ID
        return [$this->id];
    }
}