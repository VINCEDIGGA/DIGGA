# DIGG-A prerequisites skills.


CI/CD :
https://www.youtube.com/watch?v=AknbizcLq4w

System design : 

https://www.youtube.com/watch?v=SE2KF-vxvS0

Docker : 

https://www.youtube.com/watch?v=3c-iBn73dDE

MCP : 

https://www.youtube.com/watch?v=oblaHqULUHk


# Quick Git \& GitHub Guide



\## 1. Essential Commands

| Command | Action | Watch Out For |

| :--- | :--- | :--- |

| `git status` | Shows modified/staged files \[cite: 1]. | Always run this first \[cite: 1]. |

| `git add <file>` | Stages files for commit \[cite: 1]. | Avoid `git add .` without a `.gitignore` \[cite: 1]. |

| `git commit -m "msg"` | Saves a local snapshot \[cite: 1]. | Keep messages clear and descriptive \[cite: 1]. |

| `git push origin <branch>` | Uploads local commits to GitHub \[cite: 1]. | Don't use `--force` on shared branches \[cite: 1]. |

| `git pull origin <branch>` | Downloads \& merges remote changes \[cite: 1]. | Can trigger surprise merge conflicts \[cite: 1]. |

| `git merge <branch>` | Combines another branch into yours \[cite: 1]. | Watch out for overlapping code changes \[cite: 1]. |



\## 2. The Industry Fix: Branches \& PRs

Never push directly to `main` \[cite: 1]. Instead:

1\. `git pull origin main` (Get latest) \[cite: 1]

2\. `git checkout -b feat/my-work` (Create branch) \[cite: 1]

3\. Work, `add`, and `commit` \[cite: 1].

4\. `git push -u origin feat/my-work` (Push branch) \[cite: 1]

5\. Open a \*\*Pull Request (PR)\*\* on GitHub for review and merging \[cite: 1].



\### 2.1 Solo Routine (Working Alone)

```bash

git status

git add .

git commit -m "feat: short description"

git pull origin main

git push origin main

```



\## 3. Merge Conflicts

A conflict happens when two branches change the \*\*exact same line\*\* of code in different ways \[cite: 1]. Git pauses the merge and adds markers (`<<<<<<<`, `=======`, `>>>>>>>`) inside the file so you can fix it manually \[cite: 1].



\### 3.1 How to Resolve

1\. \*\*Edit:\*\* Open the file, keep the correct code, and delete all Git markers (`<<<<<<<`, `=======`, `>>>>>>>`) \[cite: 1].

2\. \*\*Stage:\*\* Run `git add <file>` to mark it resolved \[cite: 1].

3\. \*\*Commit:\*\* Run `git commit` to finish the merge \[cite: 1].



\*(Stuck? Run `git merge --abort` to cancel and start over \[cite: 1]).\*



