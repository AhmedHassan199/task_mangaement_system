<?php


namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponseHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\AddDependenciesRequest;
use App\Http\Requests\AssignTaskToUsersRequest;
use App\Http\Requests\DestroyTaskRequest;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Requests\UpdateTaskStatusRequest;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TaskController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Task::class);
        $authUser = Auth::user();
        $query = Task::query();
        if ($authUser->role == 'user') {
            $query->whereHas('assignees', function ($q) use ($authUser) {
                $q->where('user_id', $authUser->id);
            });
        }
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('due_date_from') && $request->has('due_date_to')) {
            $query->whereBetween('due_date', [$request->due_date_from, $request->due_date_to]);
        }
        if ($request->has('user_id') &&  $authUser->role == 'manager') {
            $query->whereHas('assignees', function ($q) use ($request) {
                $q->where('user_id', $request->user_id);
            });
        }
        // list tasks with( dependencies , assignees)
        $tasks = $query->with('dependencies', 'assignees')->get();
        // list tasks without( dependencies , assignees)
        // $tasks = $query->get();
        return ApiResponseHelper::success(TaskResource::collection($tasks));
    }

    public function store(StoreTaskRequest $request)
    {
        $this->authorize('create', Task::class);

        try {
            $task = Task::create($request->validated());
            return ApiResponseHelper::success(new TaskResource($task), 'Successfully created task', 201);
        } catch (\Exception $e) {
            return ApiResponseHelper::error('Task creation failed', 500, ['error' => $e->getMessage()]);
        }
    }

    public function show($id)
    {
        try {
            $task = Task::findOrFail($id);
            $this->authorize('view', $task);
            $task->load('dependencies', 'assignees');
            return ApiResponseHelper::success(new TaskResource($task), 'Successfully retrieved task', 200);
        } catch (ModelNotFoundException $e) {
            return ApiResponseHelper::error('Task not found', 404);
        } catch (\Exception $e) {
            return ApiResponseHelper::error('An error occurred', 500, ['error' => $e->getMessage()]);
        }
    }

    public function update(UpdateTaskRequest $request, $id)
    {
        try {
            $task = Task::findOrFail($id);
            $this->authorize('update', $task);

            $validated = $request->validated();

            // Check if task status is being updated to 'completed'
            if (isset($validated['status']) && $validated['status'] === 'completed') {
                if (!$task->allDependenciesCompleted()) {
                    return ApiResponseHelper::error('All dependencies must be completed before this task can be completed.', 400);
                }
            }

            $task->update($validated);

            if ($request->has('assignees')) {
                $task->assignees()->sync($validated['assignees']);
            }

            $task->load('dependencies', 'assignees');

            return ApiResponseHelper::success(new TaskResource($task), 'Successfully updated task', 200);
        } catch (ModelNotFoundException $e) {
            return ApiResponseHelper::error('Task not found', 404);
        }
    }

    public function updateStatus(UpdateTaskStatusRequest $request, $id)
    {
        try {
            $task = Task::findOrFail($id);
            $this->authorize('updateStatus', $task);

            // Retrieve the validated input data
            $validated = $request->validated();

            // Check if task status is being updated to 'completed'
            if ($validated['status'] === 'completed') {
                if (!$task->allDependenciesCompleted()) {
                    return ApiResponseHelper::error('All dependencies must be completed before this task can be completed.', 400);
                }
            }

            $task->status = $validated['status'];
            $task->save();

            return ApiResponseHelper::success(new TaskResource($task), 'Successfully updated task status', 200);
        } catch (ModelNotFoundException $e) {
            return ApiResponseHelper::error('Task not found', 404);
        }
    }
    /**
     * delete task by authorized user
     */
    public function destroy(DestroyTaskRequest $request)
    {
        $this->authorize('delete', Task::class);

        try {
            $task = Task::findOrFail($request->task_id);
            $task->delete();

            return ApiResponseHelper::success(null, 'The task has been deleted successfully', 200);
        } catch (ModelNotFoundException $e) {
            return ApiResponseHelper::error('Task not found', 404);
        }
    }

    public function addDependencies(AddDependenciesRequest $request, $taskId)
    {

        try {
            $mainTask = Task::findOrFail($taskId);
            $this->authorize('addDependencies', $mainTask);

            $assignedTaskIds = $request->input('assigned_tasks');
            $assignedTasks = Task::whereIn('id', $assignedTaskIds)->get();

            foreach ($assignedTasks as $assignedTask) {
                $assignedTask->update(['parent_id' => $mainTask->id]);
            }

            $mainTask->load('dependencies');

            return ApiResponseHelper::success(new TaskResource($mainTask), 'Tasks successfully assigned dependencies');
        } catch (ModelNotFoundException $e) {
            return ApiResponseHelper::error('Main task or assigned task not found', 404);
        }
    }
    public function assignTaskToUsers(AssignTaskToUsersRequest $request, $taskId)
    {
        try {
            $task = Task::findOrFail($taskId);
            $this->authorize('assignUser', $task);
            $validated = $request->validated();
            $newUserIds = $validated['users'];

            $currentUserIds = $task->users()->pluck('users.id')->toArray();

            $userIdsToAssign = array_diff($newUserIds, $currentUserIds);

            if (empty($userIdsToAssign)) {
                return ApiResponseHelper::error('All specified users are already assigned to this task', 400);
            }
            $task->users()->attach($userIdsToAssign);

            return ApiResponseHelper::success(null, 'Task assigned to users successfully');
        } catch (ModelNotFoundException $e) {
            return ApiResponseHelper::error('Task not found', 404);
        }
    }
}
